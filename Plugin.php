<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 邮箱验证用户后自动变为贡献者，配合Typecho多用户实现会员等级权限
 *
 * @package MailValidate
 * @author zizdog
 * @version 1.1.1
 * @link https://blog.zizdog.com
 */

class MailValidate_Plugin implements Typecho_Plugin_Interface
{
    const STATE_UNVERIFIED = 0;  // 未发送验证
    const STATE_PENDING = 1;     // 已发送，待验证
    const STATE_VERIFIED = 2;    // 已验证

    /** 后台面板：挂在「管理」菜单下（Typecho 的一级菜单索引 3） */
    const MENU_INDEX = 3;
    const PANEL_FILE = 'MailValidate/panel.php';

    /**
     * 激活插件方法
     *
     * 注意：Route 是追加语义（同名旧路由不清会 404 / 跳转错乱），
     * 所以这里先 remove 再 add，保证反复启用也是幂等的。
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Helper::removeRoute('MailValidateAction_Verify');
        Helper::removeRoute('MailValidateAction_Send');

        Helper::addRoute("MailValidateAction_Verify", "/MailValidate/verify", "MailValidate_Action", 'action');
        Helper::addRoute("MailValidateAction_Send", "/MailValidate/send", "MailValidate_Action", 'send');

        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();

        // 安全地检查并添加字段
        $columns = $db->fetchAll($db->query("SHOW COLUMNS FROM `{$prefix}users`"));
        $columnNames = array_map(function($col) { return $col['Field']; }, $columns);

        if (!in_array('validate_state', $columnNames)) {
            $db->query("ALTER TABLE `{$prefix}users` ADD `validate_state` INT(1) DEFAULT 0;");
        }
        if (!in_array('validate_token', $columnNames)) {
            $db->query("ALTER TABLE `{$prefix}users` ADD `validate_token` VARCHAR(64) DEFAULT NULL;");
        }
        if (!in_array('validate_token_expire', $columnNames)) {
            $db->query("ALTER TABLE `{$prefix}users` ADD `validate_token_expire` INT(10) UNSIGNED DEFAULT 0;");
        }
        if (!in_array('validate_last_send', $columnNames)) {
            $db->query("ALTER TABLE `{$prefix}users` ADD `validate_last_send` INT(10) UNSIGNED DEFAULT 0;");
        }

        // 注册完成后自动发验证邮件（Typecho 注册流程的 finishRegister 钩子）
        Typecho_Plugin::factory('Widget_Register')->finishRegister = array('MailValidate_Plugin', 'onRegister');

        Typecho_Plugin::factory('admin/menu.php')->navBar = array('MailValidate_Plugin', 'render');

        self::installPanel();

        // 日志表：没有 CREATE 权限的库会失败，但不影响插件启用
        MailValidate_Mail::ensureTable($db);
    }

    /**
     * 禁用插件方法
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        Helper::removeRoute('MailValidateAction_Verify');
        Helper::removeRoute('MailValidateAction_Send');

        try {
            Helper::removePanel(self::MENU_INDEX, self::PANEL_FILE);
        } catch (Throwable $e) {
            // 面板本来就不在，忽略
        }
    }

    /**
     * 注册后台面板（先删后加，保证幂等）
     *
     * @return void
     */
    public static function installPanel()
    {
        try {
            Helper::removePanel(self::MENU_INDEX, self::PANEL_FILE);
        } catch (Throwable $e) {
            // 忽略
        }

        try {
            Helper::addPanel(
                self::MENU_INDEX,
                self::PANEL_FILE,
                '邮箱验证',
                '未验证用户、重发邮件、SMTP 自检',
                'administrator'
            );
        } catch (Throwable $e) {
            // 忽略
        }
    }

    /**
     * 面板是否已经登记在 panelTable 里
     *
     * @return bool
     */
    private static function panelInstalled()
    {
        try {
            $panelTable = Helper::options()->panelTable;

            return isset($panelTable['file'])
                && in_array(urlencode(self::PANEL_FILE), (array) $panelTable['file'], true);
        } catch (Throwable $e) {
            return true; // 读不到就别折腾
        }
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $mode = new Typecho_Widget_Helper_Form_Element_Radio('mode',
            array('smtp' => 'smtp',
                  'mail' => 'mail()',
                  'sendmail' => 'sendmail()'),
            'smtp', '发信方式');
        $form->addInput($mode);

        $host = new Typecho_Widget_Helper_Form_Element_Text('host', NULL, 'smtp.',
            _t('SMTP地址'), _t('请填写 SMTP 服务器地址'));
        $form->addInput($host->addRule('required', _t('必须填写一个SMTP服务器地址')));

        $port = new Typecho_Widget_Helper_Form_Element_Text('port', NULL, '25',
            _t('SMTP端口'), _t('SMTP服务端口,一般为25。'));
        $port->input->setAttribute('class', 'mini');
        $form->addInput($port->addRule('required', _t('必须填写SMTP服务端口'))
            ->addRule('isInteger', _t('端口号必须是纯数字')));

        $user = new Typecho_Widget_Helper_Form_Element_Text('user', NULL, NULL,
            _t('SMTP用户'), _t('SMTP服务验证用户名,一般为邮箱名如：youname@domain.com'));
        $form->addInput($user->addRule('required', _t('SMTP服务验证用户名')));

        $pass = new Typecho_Widget_Helper_Form_Element_Password('pass', NULL, NULL,
            _t('SMTP密码'));
        $form->addInput($pass->addRule('required', _t('SMTP服务验证密码')));

        $validate = new Typecho_Widget_Helper_Form_Element_Checkbox('validate',
            array('validate' => '服务器需要验证',
                  'ssl' => 'ssl加密'),
            array('validate'), 'SMTP验证');
        $form->addInput($validate);

        $fromName = new Typecho_Widget_Helper_Form_Element_Text('fromName', NULL, NULL,
            _t('发件人名称'), _t('发件人名称，留空则使用博客标题'));
        $form->addInput($fromName);

        $fromAddr = new Typecho_Widget_Helper_Form_Element_Text('fromAddr', NULL, NULL,
            _t('发件人邮箱'),
            _t('From 头里的真实地址，留空则使用上面的 SMTP 用户。SMTP 用户名不是完整邮箱时（例如 163 只填了账号名）必须在这里补全，否则服务器会拒收。'));
        $form->addInput($fromAddr->addRule('email', _t('发件人邮箱格式不正确')));

        $titleForGuest = new Typecho_Widget_Helper_Form_Element_Text('titleForGuest', null, "请完成邮箱验证",
            _t('邮件标题'));
        $form->addInput($titleForGuest->addRule('required', _t('邮件标题不能为空')));

        $sendOnRegister = new Typecho_Widget_Helper_Form_Element_Checkbox('sendOnRegister',
            array('1' => _t('新用户注册后自动发送验证邮件')),
            array('1'), _t('注册即发信'),
            _t('关闭后，用户仍需登录后台、点导航栏按钮才会收到验证邮件。'));
        $form->addInput($sendOnRegister);

        $sendInterval = new Typecho_Widget_Helper_Form_Element_Text('sendInterval', NULL, '60',
            _t('发送间隔(秒)'), _t('同一用户两次发送验证邮件的最小间隔，默认60秒'));
        $sendInterval->input->setAttribute('class', 'mini');
        $form->addInput($sendInterval->addRule('isInteger', _t('间隔必须是纯数字')));

        $tokenExpire = new Typecho_Widget_Helper_Form_Element_Text('tokenExpire', NULL, '86400',
            _t('验证链接有效期(秒)'), _t('验证链接的有效期，默认86400秒(24小时)'));
        $tokenExpire->input->setAttribute('class', 'mini');
        $form->addInput($tokenExpire->addRule('isInteger', _t('有效期必须是纯数字')));

        $ipLimit = new Typecho_Widget_Helper_Form_Element_Text('ipHourlyLimit', NULL, '5',
            _t('同 IP 每小时上限'),
            _t('同一 IP 每小时最多触发几封验证邮件（注册即发信时用来挡批量注册），0 = 不限制。'));
        $ipLimit->input->setAttribute('class', 'mini');
        $form->addInput($ipLimit->addRule('isInteger', _t('同 IP 上限必须是纯数字')));

        $dailyLimit = new Typecho_Widget_Helper_Form_Element_Text('dailyLimit', NULL, '200',
            _t('全站每日上限'),
            _t('全站 24 小时内最多发送几封验证邮件，保护 SMTP 账号不被刷，0 = 不限制。'));
        $dailyLimit->input->setAttribute('class', 'mini');
        $form->addInput($dailyLimit->addRule('isInteger', _t('全站每日上限必须是纯数字')));

        $blockDomains = new Typecho_Widget_Helper_Form_Element_Textarea('blockDomains', NULL, NULL,
            _t('额外拦截的邮箱域名'),
            _t('一行一个域名（如 example.com，子域一并拦截）。内置常见一次性邮箱列表，这里只填要额外加的。'));
        $form->addInput($blockDomains);
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * 后台导航栏：未验证用户显示「验证邮件提升权限」，管理员同时自愈面板登记
     *
     * @return void
     */
    public static function render()
    {
        Typecho_Widget::widget('Widget_User')->to($user);

        if (!$user->hasLogin()) {
            return;
        }

        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select('validate_state')->from('table.users')->where('uid = ?', $user->uid));
        $state = isset($row['validate_state']) ? (int)$row['validate_state'] : 0;

        if ($state === self::STATE_UNVERIFIED) {
            echo '<a class="message" href="/MailValidate/send" style="background:#ff6a00;color:white;padding:2px 8px;border-radius:3px;text-decoration:none;">验证邮件提升权限</a>';
        } elseif ($state === self::STATE_PENDING) {
            echo '<a class="message" href="/MailValidate/send" style="background:#ff6a00;color:white;padding:2px 8px;border-radius:3px;text-decoration:none;">点此重发验证邮件</a>';
        }
        // STATE_VERIFIED 不显示任何提示

        // 老版本（1.0.x）升级上来的站点不会重新执行 activate()，
        // 这里在管理员访问后台时补登记一次面板。
        if ($user->pass('administrator', true) && !self::panelInstalled()) {
            self::installPanel();
        }
    }

    /**
     * 注册完成后的自动发信
     *
     * 这里做三件事：读配置 → 域名黑名单/频率检查 → 生成 token 并发送。
     * 任何异常都吞掉并写日志 —— 注册本身已经成功，不能被邮件拖垮。
     *
     * @param Widget_Register $register
     * @return void
     */
    public static function onRegister($register)
    {
        $uid = 0;
        $mail = '';
        $ip = '';

        try {
            $cfg = MailValidate_Mail::config();

            if (!MailValidate_Mail::isEnabled(MailValidate_Mail::raw($cfg, 'sendOnRegister'), true)) {
                return;
            }

            $mail = trim((string) $register->mail);
            $uid = (int) $register->uid;
            $screenName = (string) $register->screenName;
            $ip = (string) Typecho_Request::getInstance()->getIp();

            if ($uid <= 0 || $mail === '') {
                return;
            }

            if (MailValidate_Mail::isBlockedDomain($mail, $cfg)) {
                MailValidate_Mail::log($uid, $mail, $ip, 'blocked', false, '邮箱域名在黑名单里，未发送验证邮件');
                return;
            }

            MailValidate_Mail::sendVerification($uid, $mail, $screenName, 'register', $ip, false, $cfg);
        } catch (Throwable $e) {
            MailValidate_Mail::log($uid, $mail, $ip, 'register', false, '异常：' . $e->getMessage());
        }
    }
}
