<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * MailValidate 前端动作：验证链接 / 发送验证邮件
 *
 * @package MailValidate
 * @author zizdog
 * @version 1.1.1
 */
class MailValidate_Action extends Typecho_Widget implements Widget_Interface_Do
{
    /** @var 数据操作对象 */
    private $_db;

    /** @var 系统配置信息 */
    private $_options;

    /** @var 当前登录用户 */
    private $_user;

    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
    }

    public function init()
    {
        $this->_db = Typecho_Db::get();
        $this->_user = $this->widget('Widget_User');
        $this->_options = $this->widget('Widget_Options');
    }

    public function execute()
    {
        return;
    }

    /**
     * 渲染结果页（fail / success），站点名做转义
     *
     * @param string $file
     * @return void
     */
    private function renderResult($file)
    {
        echo MailValidate_Mail::renderTemplate($file, array(
            '%sitename%' => htmlspecialchars((string) $this->_options->title, ENT_QUOTES, 'UTF-8'),
        ));
    }

    /**
     * 验证链接处理
     *
     * 语义：token 有效 + 状态为待验证 + 未过期 → 置为已验证并提权；
     * 其余情况一律显示失败页，不区分「token 不存在」和「已过期」以免被枚举。
     *
     * @return void
     */
    public function action()
    {
        $this->init();
        $token = trim((string) $this->request->get('token'));

        if ($token === '' || strlen($token) > 64) {
            $this->renderResult('fail.html');
            return;
        }

        try {
            $row = $this->_db->fetchRow(
                $this->_db->select('uid', 'validate_state', 'validate_token_expire', 'group')
                    ->from('table.users')
                    ->where('validate_token = ?', $token)
            );

            if (empty($row)) {
                $this->renderResult('fail.html');
                return;
            }

            $uid = (int) $row['uid'];
            $state = (int) $row['validate_state'];
            $expire = (int) $row['validate_token_expire'];

            // 已验证，友好提示
            if ($state === MailValidate_Plugin::STATE_VERIFIED) {
                $this->renderResult('success.html');
                return;
            }

            // 状态不是待验证
            if ($state !== MailValidate_Plugin::STATE_PENDING) {
                $this->renderResult('fail.html');
                return;
            }

            // 检查 token 是否过期
            if ($expire > 0 && $expire < time()) {
                MailValidate_Mail::log($uid, '', (string) $this->request->getIp(), 'verify', false, '验证链接已过期');
                $this->renderResult('fail.html');
                return;
            }

            // 更新为已验证
            $this->_db->query(
                $this->_db->update('table.users')
                    ->rows(array('validate_state' => MailValidate_Plugin::STATE_VERIFIED))
                    ->where('uid = ?', $uid)
            );

            // 提升权限：subscriber -> contributor
            if ($row['group'] === 'subscriber') {
                $this->_db->query(
                    $this->_db->update('table.users')
                        ->rows(array('group' => 'contributor'))
                        ->where('uid = ?', $uid)
                );
            }

            MailValidate_Mail::log($uid, '', (string) $this->request->getIp(), 'verify', true, '');

            $this->renderResult('success.html');
        } catch (Throwable $ex) {
            error_log('[MailValidate] 验证失败：' . $ex->getMessage());
            $this->renderResult('fail.html');
        }
    }

    /**
     * 发送验证邮件（已登录用户手动触发）
     *
     * @return void
     */
    public function send()
    {
        $this->init();
        $notice = $this->widget('Widget_Notice');

        // 检查登录状态
        if (!$this->_user->hasLogin()) {
            $notice->set('请先登录', 'notice');
            $this->response->goBack();
            return;
        }

        $mail = trim((string) $this->_user->mail);
        $uid = (int) $this->_user->uid;

        // 检查邮箱是否填写
        if ($mail === '') {
            $notice->set('请先在个人资料中填写邮箱地址', 'notice');
            $this->response->goBack();
            return;
        }

        // 获取当前状态
        $row = $this->_db->fetchRow(
            $this->_db->select('validate_state', 'validate_last_send')
                ->from('table.users')
                ->where('uid = ?', $uid)
        );

        $state = isset($row['validate_state']) ? (int) $row['validate_state'] : 0;
        $lastSend = isset($row['validate_last_send']) ? (int) $row['validate_last_send'] : 0;

        // 已验证无需重复
        if ($state === MailValidate_Plugin::STATE_VERIFIED) {
            $notice->set('您的邮箱已验证，无需重复操作', 'success');
            $this->response->goBack();
            return;
        }

        $cfg = MailValidate_Mail::config();
        $interval = MailValidate_Mail::getInt($cfg, 'sendInterval', 60);

        // 频率限制（同一用户）
        if ($lastSend > 0 && (time() - $lastSend) < $interval) {
            $wait = $interval - (time() - $lastSend);
            $notice->set("发送太频繁，请 {$wait} 秒后重试", 'notice');
            $this->response->goBack();
            return;
        }

        $result = MailValidate_Mail::sendVerification(
            $uid,
            $mail,
            (string) $this->_user->screenName,
            'resend',
            (string) $this->request->getIp(),
            false,
            $cfg
        );

        if (!empty($result['ok'])) {
            $notice->set('邮件发送成功，请查收邮箱完成验证', 'success');
        } elseif (!empty($result['limited'])) {
            $notice->set($result['message'], 'notice');
        } else {
            // 不把 SMTP 原始报错回显给普通用户，细节在插件后台「最近日志」里
            $notice->set('邮件发送失败，请稍后重试；若持续失败请联系管理员', 'notice');
        }

        $this->response->goBack();
    }
}
