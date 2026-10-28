<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * MailValidate 公共邮件层
 *
 * 把「读配置、发信、渲染模板、写日志、限流、域名黑名单」集中在一处，
 * 供 Plugin（注册自动发信）、Action（手动发信 / 验证）和 panel（后台面板）复用。
 *
 * 设计约束：
 * 1. 任何异常都不向上抛 —— 注册流程绝不能因为发信失败而中断；
 * 2. 配置缺键、配置为空都要能工作（Typecho 的 Form::getInput 陷阱的镜像问题）；
 * 3. 日志表建不出来（生产库用户无 CREATE 权限）时要自动降级，不影响主流程。
 *
 * @package MailValidate
 * @author zizdog
 * @version 1.1.0
 */
class MailValidate_Mail
{
    /** 日志表名（不含前缀） */
    const LOG_TABLE = 'mailvalidate_log';

    /** @var bool|null 日志表是否可用（请求内缓存，null = 未检测） */
    private static $tableReady = null;

    /** @var array 内置的一次性邮箱域名 */
    private static $disposable = array(
        '10minutemail.com', '10minutemail.net', '20minutemail.com', '33mail.com',
        'burnermail.io', 'discard.email', 'dispostable.com', 'emailfake.com',
        'emailondeck.com', 'fakeinbox.com', 'generator.email', 'getnada.com',
        'guerrillamail.com', 'guerrillamail.net', 'inboxkitten.com', 'luxusmail.org',
        'mailcatch.com', 'maildrop.cc', 'mailinator.com', 'mailinator.net',
        'mailnesia.com', 'mintemail.com', 'moakt.com', 'mohmal.com',
        'sharklasers.com', 'smailpro.com', 'spamgourmet.com', 'spambox.us',
        'temp-mail.org', 'tempinbox.com', 'tempmail.com', 'tempmailo.com',
        'throwawaymail.com', 'trashmail.com', 'yopmail.com',
    );

    /* ============================================================== */
    /* 配置读取（全部带默认值，绝不因缺键抛错）                        */
    /* ============================================================== */

    /**
     * 读取插件配置对象
     *
     * @return object|null
     */
    public static function config()
    {
        try {
            return Helper::options()->plugin('MailValidate');
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 从配置对象取字符串（数组取第一个元素；空串回落到默认值）
     *
     * @param object|null $cfg
     * @param string      $key
     * @param string      $default
     * @return string
     */
    public static function get($cfg, $key, $default = '')
    {
        if (!is_object($cfg)) {
            return $default;
        }

        try {
            $value = $cfg->$key;
        } catch (Throwable $e) {
            return $default;
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }

    /**
     * 取原始配置值（用于勾选项这类可能是 null / 数组的值）
     *
     * @param object|null $cfg
     * @param string      $key
     * @return mixed
     */
    public static function raw($cfg, $key)
    {
        if (!is_object($cfg)) {
            return null;
        }

        try {
            return $cfg->$key;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 取整数值
     *
     * @param object|null $cfg
     * @param string      $key
     * @param int         $default
     * @return int
     */
    public static function getInt($cfg, $key, $default)
    {
        $value = self::get($cfg, $key, '');

        if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
            return (int) $default;
        }

        return (int) $value;
    }

    /**
     * 勾选项转数组
     *
     * @param mixed $value
     * @return array
     */
    public static function toList($value)
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        if ($value === null || $value === '') {
            return array();
        }

        return array((string) $value);
    }

    /**
     * 判断勾选项是否开启
     *
     * 缺键（null）用 $default；存了空数组表示「明确取消勾选」。
     *
     * @param mixed $value
     * @param bool  $default
     * @return bool
     */
    public static function isEnabled($value, $default = true)
    {
        if ($value === null) {
            return (bool) $default;
        }

        if (is_array($value)) {
            return count($value) > 0;
        }

        return in_array(strtolower(trim((string) $value)), array('1', 'on', 'yes', 'true'), true);
    }

    /**
     * 解析发件人地址：优先「发件人地址」，其次 SMTP 用户名
     *
     * SMTP 用户名在半数邮箱服务上就是完整地址；直接拿它当 From 在
     * 163 / QQ 这类服务上会被拒（MAIL FROM 必须是合法地址）。
     *
     * @param object|null $cfg
     * @return string|null 合法地址，或 null（配置不完整）
     */
    public static function fromAddress($cfg)
    {
        $candidates = array(
            self::get($cfg, 'fromAddr', ''),
            self::get($cfg, 'user', ''),
        );

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }

    /* ============================================================== */
    /* 令牌 / 地址                                                     */
    /* ============================================================== */

    /**
     * 生成 32 位十六进制 token（与库内 varchar(32) 对齐）
     *
     * @return string
     */
    public static function newToken()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(16));
        }

        return md5(uniqid(mt_rand(), true) . microtime(true));
    }

    /**
     * 生成验证地址
     *
     * @param string $token
     * @return string
     */
    public static function verifyUrl($token)
    {
        return rtrim(Helper::options()->siteUrl, '/') . '/MailValidate/verify?token=' . rawurlencode($token);
    }

    /* ============================================================== */
    /* 限流 / 黑名单                                                   */
    /* ============================================================== */

    /**
     * 发送前的频率检查
     *
     * 全站日上限用日志表统计；日志表不可用时退化用 users.validate_last_send
     * （只能近似统计，但足以保护 SMTP 账号）。同一 IP 的小时上限只在有日志表时生效。
     *
     * @param string      $ip
     * @param object|null $cfg
     * @param string      $reason 失败原因（引用返回）
     * @return bool
     */
    public static function canSend($ip, $cfg, &$reason = '')
    {
        $reason = '';
        $db = Typecho_Db::get();
        $hasTable = self::ensureTable($db);
        $daily = self::getInt($cfg, 'dailyLimit', 200);

        if ($daily > 0) {
            $since = time() - 86400;

            if ($hasTable) {
                $row = $db->fetchRow(
                    $db->select(array('COUNT(*)' => 'num'))
                        ->from('table.' . self::LOG_TABLE)
                        ->where('status = ?', 1)
                        ->where('created > ?', $since)
                );
            } else {
                $row = $db->fetchRow(
                    $db->select(array('COUNT(*)' => 'num'))
                        ->from('table.users')
                        ->where('validate_last_send > ?', $since)
                );
            }

            if ((int) ($row['num'] ?? 0) >= $daily) {
                $reason = '本站今日发信量已达上限，请明天再试';
                return false;
            }
        }

        $ipLimit = self::getInt($cfg, 'ipHourlyLimit', 5);

        if ($ipLimit > 0 && $ip !== '' && $hasTable) {
            $row = $db->fetchRow(
                $db->select(array('COUNT(*)' => 'num'))
                    ->from('table.' . self::LOG_TABLE)
                    ->where('ip = ?', $ip)
                    ->where('status = ?', 1)
                    ->where('created > ?', time() - 3600)
            );

            if ((int) ($row['num'] ?? 0) >= $ipLimit) {
                $reason = '发送过于频繁，请稍后再试（同一 IP 每小时最多 ' . $ipLimit . ' 封）';
                return false;
            }
        }

        return true;
    }

    /**
     * 邮箱域名是否在黑名单里（精确或子域匹配）
     *
     * @param string      $mail
     * @param object|null $cfg
     * @return bool
     */
    public static function isBlockedDomain($mail, $cfg)
    {
        $mail = strtolower(trim((string) $mail));
        $at = strrpos($mail, '@');

        if ($at === false) {
            return false;
        }

        $domain = substr($mail, $at + 1);
        $extra = self::get($cfg, 'blockDomains', '');
        $extraList = preg_split('/[\s,;]+/', strtolower($extra), -1, PREG_SPLIT_NO_EMPTY);

        foreach (array_merge(self::$disposable, $extraList ?: array()) as $blocked) {
            $blocked = trim($blocked, ". \t\n\r\0\x0B");

            if ($blocked === '') {
                continue;
            }

            if ($domain === $blocked || substr($domain, -strlen($blocked) - 1) === '.' . $blocked) {
                return true;
            }
        }

        return false;
    }

    /* ============================================================== */
    /* 日志                                                            */
    /* ============================================================== */

    /**
     * 日志表名（含前缀）
     *
     * @param Typecho_Db $db
     * @return string
     */
    public static function tableName($db)
    {
        return $db->getPrefix() . self::LOG_TABLE;
    }

    /**
     * 确保日志表存在
     *
     * 生产库用户可能没有 CREATE 权限 → 返回 false，调用方降级处理。
     *
     * @param Typecho_Db|null $db
     * @return bool
     */
    public static function ensureTable($db = null)
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }

        try {
            $db = $db ?: Typecho_Db::get();
            $table = self::tableName($db);
            $db->query(
                'CREATE TABLE IF NOT EXISTS `' . $table . '` ('
                . ' `id` int(10) unsigned NOT NULL AUTO_INCREMENT,'
                . ' `uid` int(10) unsigned NOT NULL DEFAULT 0,'
                . ' `mail` varchar(150) DEFAULT NULL,'
                . ' `ip` varchar(45) DEFAULT NULL,'
                . ' `action` varchar(20) NOT NULL DEFAULT \'\','
                . ' `status` tinyint(1) NOT NULL DEFAULT 0,'
                . ' `message` text,'
                . ' `created` int(10) unsigned NOT NULL DEFAULT 0,'
                . ' PRIMARY KEY (`id`),'
                . ' KEY `created` (`created`),'
                . ' KEY `ip` (`ip`),'
                . ' KEY `uid` (`uid`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            self::$tableReady = true;
        } catch (Throwable $e) {
            self::$tableReady = false;
            error_log('[MailValidate] 日志表不可用：' . $e->getMessage());
        }

        return self::$tableReady;
    }

    /**
     * 记录一次发送/验证动作（失败只写 error_log，不影响主流程）
     *
     * @param int    $uid
     * @param string $mail
     * @param string $ip
     * @param string $action register|resend|verify|test|blocked
     * @param bool   $ok
     * @param string $message
     * @return void
     */
    public static function log($uid, $mail, $ip, $action, $ok, $message = '')
    {
        $uid = (int) $uid;
        $mail = (string) $mail;
        $ip = (string) $ip;
        $action = (string) $action;

        try {
            $db = Typecho_Db::get();

            if (!self::ensureTable($db)) {
                error_log(sprintf(
                    '[MailValidate] %s uid=%d mail=%s ok=%d %s',
                    $action,
                    $uid,
                    $mail,
                    $ok ? 1 : 0,
                    $message
                ));
                return;
            }

            $db->query(
                $db->insert('table.' . self::LOG_TABLE)->rows(array(
                    'uid' => $uid,
                    'mail' => $mail,
                    'ip' => $ip,
                    'action' => $action,
                    'status' => $ok ? 1 : 0,
                    'message' => (string) $message,
                    'created' => time(),
                ))
            );
        } catch (Throwable $e) {
            error_log('[MailValidate] 写日志失败：' . $e->getMessage());
        }
    }

    /* ============================================================== */
    /* 发信                                                            */
    /* ============================================================== */

    /**
     * 读模板并替换变量
     *
     * @param string $file 模板文件名（插件根目录下）
     * @param array  $vars
     * @return string
     */
    public static function renderTemplate($file, $vars = array())
    {
        $path = dirname(__FILE__) . '/' . $file;
        $content = is_file($path) ? file_get_contents($path) : '';

        foreach ($vars as $key => $value) {
            $content = str_replace($key, (string) $value, $content);
        }

        return $content;
    }

    /**
     * 用 PHPMailer 发一封 HTML 邮件
     *
     * @param string      $to
     * @param string      $toName
     * @param string      $subject
     * @param string      $html
     * @param object|null $cfg
     * @return array array(bool $ok, string $error)
     */
    public static function send($to, $toName, $subject, $html, $cfg = null)
    {
        $cfg = $cfg ?: self::config();

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return array(false, '收件人邮箱格式不正确');
        }

        $from = self::fromAddress($cfg);

        if ($from === null) {
            return array(false, '发件人地址无效：请在插件设置里填写完整的发件人邮箱（如 i@zizdog.com）');
        }

        try {
            require_once dirname(__FILE__) . '/lib/Exception.php';
            require_once dirname(__FILE__) . '/lib/PHPMailer.php';
            require_once dirname(__FILE__) . '/lib/SMTP.php';

            $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->Encoding = 'base64';
            $mailer->Timeout = 10;

            switch (self::get($cfg, 'mode', 'smtp')) {
                case 'mail':
                    $mailer->isMail();
                    break;
                case 'sendmail':
                    $mailer->isSendmail();
                    break;
                case 'smtp':
                default:
                    $host = self::get($cfg, 'host', '');

                    if ($host === '') {
                        return array(false, '未配置 SMTP 服务器地址');
                    }

                    $validate = self::toList(is_object($cfg) ? $cfg->validate : null);

                    $mailer->isSMTP();
                    $mailer->Host = $host;
                    $mailer->Port = self::getInt($cfg, 'port', 25);
                    $mailer->SMTPAuth = in_array('validate', $validate, true);
                    $mailer->Username = self::get($cfg, 'user', '');
                    $mailer->Password = self::get($cfg, 'pass', '');

                    if (in_array('ssl', $validate, true)) {
                        $mailer->SMTPSecure = 'ssl';
                    }

                    break;
            }

            $fromName = self::get($cfg, 'fromName', '');

            if ($fromName === '') {
                $fromName = Helper::options()->title;
            }

            $mailer->setFrom($from, $fromName);
            $mailer->addReplyTo($from, $fromName);
            $mailer->addAddress($to, (string) $toName);
            $mailer->Subject = (string) $subject;
            $mailer->isHTML(true);
            $mailer->Body = (string) $html;
            $mailer->AltBody = self::plainText($html);

            $mailer->send();

            return array(true, '');
        } catch (Throwable $e) {
            return array(false, $e->getMessage());
        }
    }

    /**
     * HTML 转纯文本（邮件客户端不显示 HTML 时的兜底）
     *
     * @param string $html
     * @return string
     */
    private static function plainText($html)
    {
        $text = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', (string) $html);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/(p|div|tr|li|h[1-6])>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /* ============================================================== */
    /* 业务：给某个用户发验证邮件                                      */
    /* ============================================================== */

    /**
     * 生成 token、落库、发信、写日志
     *
     * @param int         $uid
     * @param string      $mail
     * @param string      $screenName
     * @param string      $action       register|resend
     * @param string      $ip
     * @param bool        $skipLimit    后台手动重发时跳过频率限制
     * @param object|null $cfg
     * @return array array('ok' => bool, 'message' => string, 'limited' => bool)
     */
    public static function sendVerification($uid, $mail, $screenName, $action, $ip, $skipLimit = false, $cfg = null)
    {
        $uid = (int) $uid;
        $mail = trim((string) $mail);
        $cfg = $cfg ?: self::config();

        if ($uid <= 0 || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            return array('ok' => false, 'message' => '用户或邮箱无效', 'limited' => false);
        }

        if (!$skipLimit) {
            $reason = '';

            if (!self::canSend($ip, $cfg, $reason)) {
                self::log($uid, $mail, $ip, 'blocked', false, $reason);
                return array('ok' => false, 'message' => $reason, 'limited' => true);
            }
        }

        try {
            $db = Typecho_Db::get();
            $token = self::newToken();
            $expire = self::getInt($cfg, 'tokenExpire', 86400);

            $previous = $db->fetchRow(
                $db->select('validate_state')->from('table.users')->where('uid = ?', $uid)
            );
            $previousState = isset($previous['validate_state']) ? (int) $previous['validate_state'] : 0;

            $db->query(
                $db->update('table.users')
                    ->rows(array(
                        'validate_token' => $token,
                        'validate_token_expire' => time() + ($expire > 0 ? $expire : 86400),
                        'validate_last_send' => time(),
                        'validate_state' => MailValidate_Plugin::STATE_PENDING,
                    ))
                    ->where('uid = ?', $uid)
            );

            $title = self::get($cfg, 'titleForGuest', '请完成邮箱验证');
            $sitename = Helper::options()->title;
            $html = self::renderTemplate('mail.html', array(
                '%sitename%' => htmlspecialchars($sitename, ENT_QUOTES, 'UTF-8'),
                '%username%' => htmlspecialchars((string) $screenName, ENT_QUOTES, 'UTF-8'),
                '%verifyurl%' => htmlspecialchars(self::verifyUrl($token), ENT_QUOTES, 'UTF-8'),
                '%useravatar%' => md5(strtolower($mail)),
            ));

            list($ok, $error) = self::send($mail, $screenName, $title, $html, $cfg);

            // 发信失败时不要把状态留在「待验证」，否则用户会以为邮件已经在路上
            if (!$ok) {
                try {
                    $db->query(
                        $db->update('table.users')
                            ->rows(array('validate_state' => $previousState))
                            ->where('uid = ?', $uid)
                    );
                } catch (Throwable $e) {
                    // 状态回滚失败不影响日志
                }
            }

            self::log($uid, $mail, $ip, $action, $ok, $ok ? '' : $error);

            return array('ok' => $ok, 'message' => $ok ? '' : $error, 'limited' => false);
        } catch (Throwable $e) {
            self::log($uid, $mail, $ip, $action, false, '异常：' . $e->getMessage());

            return array('ok' => false, 'message' => '发送时出现异常，已记录日志', 'limited' => false);
        }
    }
}
