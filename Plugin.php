<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
/**
 * 邮箱验证用户后自动变为贡献者，配合Typecho多用户实现会员等级权限
 * 
 * @package MailValidate
 * @author zizdog
 * @version 1.0.1
 * @link https://blog.zizdog.com
 */

class MailValidate_Plugin implements Typecho_Plugin_Interface
{
    const STATE_UNVERIFIED = 0;  // 未发送验证
    const STATE_PENDING = 1;     // 已发送，待验证
    const STATE_VERIFIED = 2;    // 已验证

    /**
     * 激活插件方法
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
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
        
        Typecho_Plugin::factory('admin/menu.php')->navBar = array('MailValidate_Plugin', 'render');
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

        $titleForGuest = new Typecho_Widget_Helper_Form_Element_Text('titleForGuest', null, "请完成邮箱验证",
            _t('邮件标题'));
        $form->addInput($titleForGuest->addRule('required', _t('邮件标题不能为空')));
        
        $sendInterval = new Typecho_Widget_Helper_Form_Element_Text('sendInterval', NULL, '60',
            _t('发送间隔(秒)'), _t('两次发送验证邮件的最小间隔，默认60秒'));
        $sendInterval->input->setAttribute('class', 'mini');
        $form->addInput($sendInterval->addRule('isInteger', _t('间隔必须是纯数字')));
        
        $tokenExpire = new Typecho_Widget_Helper_Form_Element_Text('tokenExpire', NULL, '86400',
            _t('验证链接有效期(秒)'), _t('验证链接的有效期，默认86400秒(24小时)'));
        $tokenExpire->input->setAttribute('class', 'mini');
        $form->addInput($tokenExpire->addRule('isInteger', _t('有效期必须是纯数字')));
    }
    
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}
    
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
    }
}
