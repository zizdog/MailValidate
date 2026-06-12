<?php
class MailValidate_Action extends Typecho_Widget implements Widget_Interface_Do
{
    /** @var 数据操作对象 */
    private $_db;
    
    /** @var 插件根目录 */
    private $_dir;
    
    /** @var 插件配置信息 */
    private $_cfg;
    
    /** @var 系统配置信息 */
    private $_options;
    
    /** @var 当前登录用户 */
    private $_user;
    
    /** @var object 邮件内容信息 */
    private $_email;
    
    public function __construct($request, $response, $params = NULL)
    {
        parent::__construct($request, $response, $params);
    }
    
    public function init()
    {
        $this->_dir = dirname(__FILE__);
        $this->_db = Typecho_Db::get();
        $this->_user = $this->widget('Widget_User');
        $this->_options = $this->widget('Widget_Options');
        $this->_cfg = Helper::options()->plugin('MailValidate');
        $this->_email = new stdClass();
    }
    
    public function execute()
    {
        return;
    }
    
    /*
     * 发送邮件
     */
    public function sendMail()
    {
        /** 载入邮件组件 */
        require_once $this->_dir . '/lib/PHPMailer.php';
        require_once $this->_dir . '/lib/SMTP.php';
        require_once $this->_dir . '/lib/Exception.php';
        $mailer = new PHPMailer\PHPMailer\PHPMailer();
        $mailer->CharSet = 'UTF-8';
        $mailer->Encoding = 'base64';

        //选择发信模式
        switch ($this->_cfg->mode)
        {
            case 'mail':
                break;
            case 'sendmail':
                $mailer->IsSendmail();
                break;
            case 'smtp':
                $mailer->IsSMTP();

                if (in_array('validate', $this->_cfg->validate)) {
                    $mailer->SMTPAuth = true;
                }

                if (in_array('ssl', $this->_cfg->validate)) {
                    $mailer->SMTPSecure = "ssl";
                }

                $mailer->Host = $this->_cfg->host;
                $mailer->Port = $this->_cfg->port;
                $mailer->Username = $this->_cfg->user;
                $mailer->Password = $this->_cfg->pass;

                break;
        }

        $mailer->SetFrom($this->_email->from, $this->_email->fromName);
        $mailer->AddReplyTo($this->_email->to, $this->_email->toName);
        $mailer->Subject = $this->_email->subject;
        $mailer->AltBody = $this->_email->altBody;
        $mailer->MsgHTML($this->_email->msgHtml);
        $mailer->AddAddress($this->_email->to, $this->_email->toName);

        $result = $mailer->Send();
        if (!$result) {
            $result = $mailer->ErrorInfo;
        }
        
        $mailer->ClearAddresses();
        $mailer->ClearReplyTos();

        return $result;
    }
    
    /**
     * 渲染模板并替换变量
     */
    private function renderTemplate($file, $vars = array())
    {
        $content = file_get_contents($this->_dir . '/' . $file);
        foreach ($vars as $key => $val) {
            $content = str_replace($key, $val, $content);
        }
        return $content;
    }
    
    /*
     * 验证链接处理
     */
    public function action()
    {
        $this->init();
        $token = $this->request->token;
        
        if (empty($token) || strlen($token) > 64) {
            echo $this->renderTemplate('fail.html', array(
                '%sitename%' => $this->_options->title
            ));
            return;
        }
        
        try {
            $row = $this->_db->fetchRow(
                $this->_db->select('validate_state', 'validate_token_expire', 'group')
                    ->from('table.users')
                    ->where('validate_token = ?', $token)
            );
            
            if (empty($row)) {
                echo $this->renderTemplate('fail.html', array(
                    '%sitename%' => $this->_options->title
                ));
                return;
            }
            
            $state = (int)$row['validate_state'];
            $expire = (int)$row['validate_token_expire'];
            
            // 已验证，友好提示
            if ($state === MailValidate_Plugin::STATE_VERIFIED) {
                echo $this->renderTemplate('success.html', array(
                    '%sitename%' => $this->_options->title
                ));
                return;
            }
            
            // 状态不是待验证
            if ($state !== MailValidate_Plugin::STATE_PENDING) {
                echo $this->renderTemplate('fail.html', array(
                    '%sitename%' => $this->_options->title
                ));
                return;
            }
            
            // 检查 token 是否过期
            if ($expire > 0 && $expire < time()) {
                echo $this->renderTemplate('fail.html', array(
                    '%sitename%' => $this->_options->title
                ));
                return;
            }
            
            // 更新为已验证
            $this->_db->query(
                $this->_db->update('table.users')
                    ->rows(array('validate_state' => MailValidate_Plugin::STATE_VERIFIED))
                    ->where('validate_token = ?', $token)
            );
            
            // 提升权限：subscriber -> contributor
            if ($row['group'] === 'subscriber') {
                $this->_db->query(
                    $this->_db->update('table.users')
                        ->rows(array('group' => 'contributor'))
                        ->where('validate_token = ?', $token)
                );
            }
            
            echo $this->renderTemplate('success.html', array(
                '%sitename%' => $this->_options->title
            ));
            
        } catch (Exception $ex) {
            echo $this->renderTemplate('fail.html', array(
                '%sitename%' => $this->_options->title
            ));
        }
    }
    
    /*
     * 发送验证邮件
     */
    public function send()
    {
        $this->init();
        
        // 检查登录状态
        if (!$this->_user->hasLogin()) {
            $this->widget('Widget_Notice')->set('请先登录', 'notice');
            $this->response->goBack();
            return;
        }
        
        // 检查邮箱是否填写
        if (empty($this->_user->mail)) {
            $this->widget('Widget_Notice')->set('请先在个人资料中填写邮箱地址', 'notice');
            $this->response->goBack();
            return;
        }
        
        // 获取当前状态
        $row = $this->_db->fetchRow(
            $this->_db->select('validate_state', 'validate_last_send')
                ->from('table.users')
                ->where('uid = ?', $this->_user->uid)
        );
        
        $state = isset($row['validate_state']) ? (int)$row['validate_state'] : 0;
        $lastSend = isset($row['validate_last_send']) ? (int)$row['validate_last_send'] : 0;
        
        // 已验证无需重复
        if ($state === MailValidate_Plugin::STATE_VERIFIED) {
            $this->widget('Widget_Notice')->set('您的邮箱已验证，无需重复操作', 'success');
            $this->response->goBack();
            return;
        }
        
        // 频率限制
        $interval = isset($this->_cfg->sendInterval) ? (int)$this->_cfg->sendInterval : 60;
        if ($lastSend > 0 && (time() - $lastSend) < $interval) {
            $wait = $interval - (time() - $lastSend);
            $this->widget('Widget_Notice')->set("发送太频繁，请 {$wait} 秒后重试", 'notice');
            $this->response->goBack();
            return;
        }
        
        // 生成安全 token
        if (function_exists('random_bytes')) {
            $token = bin2hex(random_bytes(16));
        } else {
            $token = md5(uniqid(mt_rand(), true) . $this->_user->mail . time());
        }
        
        // 计算过期时间
        $expire = isset($this->_cfg->tokenExpire) ? (int)$this->_cfg->tokenExpire : 86400;
        $expireTime = time() + $expire;
        
        // 更新数据库：token、过期时间、发送时间、状态
        $this->_db->query(
            $this->_db->update('table.users')
                ->rows(array(
                    'validate_token' => $token,
                    'validate_token_expire' => $expireTime,
                    'validate_last_send' => time(),
                    'validate_state' => MailValidate_Plugin::STATE_PENDING
                ))
                ->where('uid = ?', $this->_user->uid)
        );
        
        // 准备邮件内容
        $this->_email->from = $this->_cfg->user;
        $this->_email->fromName = $this->_cfg->fromName ? $this->_cfg->fromName : $this->_options->title;
        $this->_email->to = $this->_user->mail;
        $this->_email->toName = $this->_user->screenName;
        $this->_email->subject = $this->_cfg->titleForGuest;
        
        $mailcontent = file_get_contents($this->_dir . "/mail.html");
        $keys = array(
            '%sitename%' => $this->_options->title,
            '%username%' => $this->_user->screenName,
            '%verifyurl%' => $this->_options->siteUrl . "MailValidate/verify?token=" . $token,
            '%useravatar%' => md5(strtolower(trim($this->_user->mail)))
        );
        
        foreach ($keys as $key => $val) {
            $mailcontent = str_replace($key, $val, $mailcontent);
        }
        
        $this->_email->altBody = strip_tags($mailcontent);
        $this->_email->msgHtml = $mailcontent;
        
        $result = $this->sendMail();
        
        if ($result === true) {
            $this->widget('Widget_Notice')->set('邮件发送成功，请查收邮箱完成验证', 'success');
        } else {
            $this->widget('Widget_Notice')->set('邮件发送失败：' . $result, 'notice');
        }
    
        $this->response->goBack();
    }
}
