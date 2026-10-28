<?php
/**
 * MailValidate 后台面板
 *
 * 入口：后台 → 管理 → 邮箱验证
 *      （admin/extending.php?panel=MailValidate/panel.php）
 *
 * 能力：按状态筛选用户、手动重发验证邮件、标记/重置验证状态、
 *       SMTP 自检、查看最近的发送日志。
 *
 * @package MailValidate
 * @author zizdog
 * @version 1.1.2
 */

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

require_once dirname(__FILE__) . '/Plugin.php';

$db = Typecho_Db::get();
$options = Helper::options();
$user = Typecho_Widget::widget('Widget_User');
$security = Typecho_Widget::widget('Widget_Security');
$request = Typecho_Request::getInstance();
$response = $options->response;

$user->pass('administrator');

$panelUrl = $options->adminUrl('extending.php?panel=' . urlencode('MailValidate/panel.php'), true);
$hasTable = MailValidate_Mail::ensureTable($db);

/**
 * 跳回面板并带上一条提示
 *
 * @param string $message
 * @param string $type success|notice
 * @return void
 */
$go = function ($message, $type = 'success') use ($response, $panelUrl) {
    $response->redirect($panelUrl . '&mv=' . rawurlencode($message) . '&mvtype=' . rawurlencode($type));
    exit;
};

/* ============================================================== */
/* 动作处理（POST + 固定后缀 token，避免依赖 Referer）             */
/* ============================================================== */
if ($request->isPost()) {
    $expected = (string) $security->getToken('MailValidatePanel');
    $posted = (string) $request->get('_');

    if ($expected === '' || !hash_equals($expected, $posted)) {
        $go('安全令牌校验失败，请刷新页面后重试', 'notice');
    }

    $do = (string) $request->get('do');
    $uid = (int) $request->get('uid');
    $ip = (string) $request->getIp();

    if ($do === 'test') {
        $to = trim((string) $user->mail);

        if ($to === '') {
            $go('当前管理员账号没有填邮箱，无法自检', 'notice');
        }

        list($ok, $error) = MailValidate_Mail::send(
            $to,
            (string) $user->screenName,
            '[自检] ' . $options->title . ' 邮件发送正常',
            '<p>这是一封来自 MailValidate 插件的测试邮件，收到它说明 SMTP 配置可用。</p>'
            . '<p>发送时间：' . date('Y-m-d H:i:s') . '</p>',
            MailValidate_Mail::config()
        );

        MailValidate_Mail::log((int) $user->uid, $to, $ip, 'test', $ok, $ok ? '' : $error);

        $go($ok ? '测试邮件已发送到 ' . $to . '，请查收' : '测试邮件发送失败：' . $error, $ok ? 'success' : 'notice');
    }

    if ($do === 'clear') {
        if ($hasTable) {
            $db->query($db->delete('table.' . MailValidate_Mail::LOG_TABLE)->where('created < ?', time() - 30 * 86400));
        }

        $go('已清理 30 天前的发送日志');
    }

    if ($uid <= 0) {
        $go('缺少用户 ID', 'notice');
    }

    $row = $db->fetchRow(
        $db->select('uid', 'name', 'screenName', 'mail', 'group', 'validate_state')
            ->from('table.users')
            ->where('uid = ?', $uid)
    );

    if (empty($row)) {
        $go('用户不存在', 'notice');
    }

    if ($do === 'resend') {
        if ((int) $row['validate_state'] === MailValidate_Plugin::STATE_VERIFIED) {
            $go('该用户已经验证过了，无需重发', 'notice');
        }

        if (trim((string) $row['mail']) === '') {
            $go('该用户没有填邮箱', 'notice');
        }

        // 后台手动重发跳过频率限制（管理员主动操作）
        $result = MailValidate_Mail::sendVerification(
            (int) $row['uid'],
            (string) $row['mail'],
            (string) $row['screenName'],
            'resend',
            $ip,
            true
        );

        $go(
            $result['ok'] ? '已重新发送验证邮件到 ' . $row['mail'] : '发送失败：' . $result['message'],
            $result['ok'] ? 'success' : 'notice'
        );
    }

    if ($do === 'mark') {
        $target = (int) $request->get('state');

        if (!in_array($target, array(0, 1, 2), true)) {
            $go('状态值不合法', 'notice');
        }

        $db->query(
            $db->update('table.users')
                ->rows(array('validate_state' => $target))
                ->where('uid = ?', $uid)
        );

        // 手动标记为已验证时，和真实验证走同样的提权逻辑
        if ($target === MailValidate_Plugin::STATE_VERIFIED && $row['group'] === 'subscriber') {
            $db->query(
                $db->update('table.users')
                    ->rows(array('group' => 'contributor'))
                    ->where('uid = ?', $uid)
            );
        }

        MailValidate_Mail::log($uid, (string) $row['mail'], $ip, 'mark', true, '管理员改为状态 ' . $target);

        $labels = array(0 => '未验证', 1 => '待验证', 2 => '已验证');
        $go('已把 ' . $row['name'] . ' 标记为「' . $labels[$target] . '」');
    }

    $go('未知操作', 'notice');
}

/* ============================================================== */
/* 列表数据                                                        */
/* ============================================================== */
$filter = (string) $request->get('state', 'all');

if (!in_array($filter, array('all', '0', '1', '2'), true)) {
    $filter = 'all';
}

$keyword = trim((string) $request->get('q'));
$page = max(1, (int) $request->get('p'));
$perPage = 20;

$buildWhere = function ($select) use ($db, $filter, $keyword) {
    if ($filter !== 'all') {
        $select->where('validate_state = ?', (int) $filter);
    }

    if ($keyword !== '') {
        $like = '%' . str_replace(array('%', '_'), array('\\%', '\\_'), $keyword) . '%';
        $select->where('(name LIKE ? OR screenName LIKE ? OR mail LIKE ?)', $like, $like, $like);
    }

    return $select;
};

$countQuery = $buildWhere($db->select(array('COUNT(*)' => 'num'))->from('table.users'));
$total = (int) ($db->fetchRow($countQuery)['num'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);

$listQuery = $buildWhere(
    $db->select('uid', 'name', 'screenName', 'mail', 'group', 'validate_state', 'validate_last_send', 'validate_token_expire')
        ->from('table.users')
);
$listQuery->order('uid', Typecho_Db::SORT_DESC)->offset(($page - 1) * $perPage)->limit($perPage);
$users = $db->fetchAll($listQuery);

$stats = array('all' => 0, 'unverified' => 0, 'pending' => 0, 'verified' => 0);

foreach (array(0 => 'unverified', 1 => 'pending', 2 => 'verified') as $state => $key) {
    $row = $db->fetchRow(
        $db->select(array('COUNT(*)' => 'num'))->from('table.users')->where('validate_state = ?', $state)
    );
    $stats[$key] = (int) ($row['num'] ?? 0);
}

$stats['all'] = $stats['unverified'] + $stats['pending'] + $stats['verified'];

$sendsOk = $sendsFail = 0;

if ($hasTable) {
    $since = time() - 86400;
    $sendsOk = (int) ($db->fetchRow(
        $db->select(array('COUNT(*)' => 'num'))->from('table.' . MailValidate_Mail::LOG_TABLE)
            ->where('status = ?', 1)->where('created > ?', $since)
    )['num'] ?? 0);
    $sendsFail = (int) ($db->fetchRow(
        $db->select(array('COUNT(*)' => 'num'))->from('table.' . MailValidate_Mail::LOG_TABLE)
            ->where('status = ?', 0)->where('created > ?', $since)
    )['num'] ?? 0);
}

$logs = array();

if ($hasTable) {
    $logs = $db->fetchAll(
        $db->select('id', 'uid', 'mail', 'ip', 'action', 'status', 'message', 'created')
            ->from('table.' . MailValidate_Mail::LOG_TABLE)
            ->order('id', Typecho_Db::SORT_DESC)
            ->limit(40)
    );
}

$cfg = MailValidate_Mail::config();
$fromAddr = MailValidate_Mail::fromAddress($cfg);
$token = $security->getToken('MailValidatePanel');

/**
 * 转义输出
 *
 * @param mixed $value
 * @return string
 */
function mv_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * 状态标签
 *
 * @param int $state
 * @return string
 */
function mv_state_label($state)
{
    $labels = array(
        MailValidate_Plugin::STATE_UNVERIFIED => '未验证',
        MailValidate_Plugin::STATE_PENDING => '待验证',
        MailValidate_Plugin::STATE_VERIFIED => '已验证',
    );

    return $labels[(int) $state] ?? '未知';
}

/**
 * 用户组中文名
 *
 * @param string $group
 * @return string
 */
function mv_group_label($group)
{
    $labels = array(
        'administrator' => '管理员',
        'editor' => '编辑',
        'contributor' => '贡献者',
        'subscriber' => '关注者',
        'visitor' => '访问者',
    );

    return $labels[$group] ?? $group;
}

/**
 * 时间戳格式化
 *
 * @param int $timestamp
 * @return string
 */
function mv_time($timestamp)
{
    $timestamp = (int) $timestamp;

    return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '—';
}

/**
 * 当前列表地址（保留筛选条件，供分页使用）
 *
 * @param array $override
 * @return string
 */
function mv_list_url($override = array())
{
    $params = array_merge(array(
        'panel' => 'MailValidate/panel.php',
        'state' => $_GET['state'] ?? 'all',
        'q' => $_GET['q'] ?? '',
        'p' => $_GET['p'] ?? 1,
    ), $override);

    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }

    return 'extending.php?' . http_build_query($params);
}

$flash = (string) $request->get('mv');
$flashType = $request->get('mvtype') === 'notice' ? 'notice' : 'success';

include rtrim(__TYPECHO_ROOT_DIR__, '/') . '/admin/header.php';
include rtrim(__TYPECHO_ROOT_DIR__, '/') . '/admin/menu.php';
?>
<main class="main">
    <div class="body container">
        <h2 class="typecho-page-title">邮箱验证</h2>

        <style>
            /* 操作列：inline-block + 外边距留白，不依赖 flex/gap（老 WebKit 也正常）；
               窄屏时整颗按钮换行，而不是把按钮文字拆开 */
            .mv-actions .mv-action-group { display: block; }
            .mv-actions form {
                display: inline-block;
                vertical-align: top;
                margin: 0 6px 6px 0;
            }
            .mv-actions form:last-child { margin-right: 0; }
            .mv-actions .btn {
                margin: 0;
                padding: 0 10px;
                white-space: nowrap;
            }
            /* 「上次发送 / 到期」两行稍微紧凑，避免行高被撑大 */
            .mv-time-cell { line-height: 1.5; white-space: nowrap; }
            /* 固定列宽，百分比才会真正生效；不然浏览器按内容分配，操作列会被挤窄 */
            .mv-table { table-layout: fixed; width: 100%; }
            .mv-table td { overflow-wrap: break-word; }
        </style>

        <?php if ($flash !== ''): ?>
            <div class="message <?php echo $flashType === 'notice' ? 'notice' : 'success'; ?>"
                 style="padding:10px 14px;border-radius:3px;margin-bottom:14px;background:<?php echo $flashType === 'notice' ? '#fdf3e7' : '#eef7ee'; ?>;">
                <?php echo mv_h($flash); ?>
            </div>
        <?php endif; ?>

        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12" style="margin-bottom:16px;">
                <p>
                    用户总数 <strong><?php echo $stats['all']; ?></strong>
                    &nbsp;·&nbsp; 未验证 <strong><?php echo $stats['unverified']; ?></strong>
                    &nbsp;·&nbsp; 待验证 <strong><?php echo $stats['pending']; ?></strong>
                    &nbsp;·&nbsp; 已验证 <strong><?php echo $stats['verified']; ?></strong>
                    &nbsp;·&nbsp; 近 24h 发送成功 <strong><?php echo $sendsOk; ?></strong>
                    / 失败 <strong><?php echo $sendsFail; ?></strong>
                </p>
                <p style="color:#888;">
                    发件人：<?php echo $fromAddr ? mv_h($fromAddr) : '<span style="color:#c33;">未配置有效发件人邮箱（设置 → 插件 → MailValidate）</span>'; ?>
                    &nbsp;·&nbsp; 注册即发信：
                    <strong><?php echo MailValidate_Mail::isEnabled(MailValidate_Mail::raw($cfg, 'sendOnRegister'), true) ? '开' : '关'; ?></strong>
                </p>
                <?php if (!$hasTable): ?>
                    <p style="color:#c33;">
                        日志表 <code>mailvalidate_log</code> 建不出来（数据库用户可能没有 CREATE 权限），
                        发送日志与同 IP 限流将降级；可按需手动建表。
                    </p>
                <?php endif; ?>
            </div>

            <div class="col-mb-12 typecho-list">
                <form method="get" class="typecho-list-operate">
                    <input type="hidden" name="panel" value="MailValidate/panel.php"/>
                    <div class="operate">
                        <div class="btn-group">
                            <?php
                            $filters = array(
                                'all' => '全部',
                                '0' => '未验证',
                                '1' => '待验证',
                                '2' => '已验证',
                            );
                            foreach ($filters as $key => $label):
                                $active = (string) $filter === (string) $key;
                                ?>
                                <a class="btn btn-s<?php echo $active ? ' primary' : ''; ?>"
                                   href="<?php echo mv_h(mv_list_url(array('state' => $key, 'p' => 1))); ?>"><?php echo $label; ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="search" role="search">
                        <input type="text" class="text-s" name="q" placeholder="用户名 / 昵称 / 邮箱"
                               value="<?php echo mv_h($keyword); ?>"/>
                        <input type="hidden" name="state" value="<?php echo mv_h($filter); ?>"/>
                        <input type="hidden" name="panel" value="MailValidate/panel.php"/>
                        <button type="submit" class="btn btn-s">筛选</button>
                    </div>
                </form>

                <form method="post" class="mv-test-form" style="margin:10px 0;">
                    <input type="hidden" name="_" value="<?php echo mv_h($token); ?>"/>
                    <input type="hidden" name="do" value="test"/>
                    <button type="submit" class="btn btn-s">发送一封测试邮件给我</button>
                    <span style="color:#888;margin-left:8px;">收件人：<?php echo mv_h($user->mail); ?></span>
                </form>

                <table class="typecho-list-table mv-table">
                    <thead>
                    <tr>
                        <th style="width:5%;">UID</th>
                        <th style="width:12%;">用户名</th>
                        <th style="width:12%;">昵称</th>
                        <th style="width:20%;">邮箱</th>
                        <th style="width:9%;">用户组</th>
                        <th style="width:9%;">验证状态</th>
                        <th style="width:15%;">上次发送 / 过期</th>
                        <th style="width:18%;">操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center;color:#888;">没有匹配的用户</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($users as $item): ?>
                        <tr>
                            <td><?php echo (int) $item['uid']; ?></td>
                            <td><?php echo mv_h($item['name']); ?></td>
                            <td><?php echo mv_h($item['screenName']); ?></td>
                            <td><?php echo mv_h($item['mail']); ?></td>
                            <td><?php echo mv_h(mv_group_label((string) $item['group'])); ?></td>
                            <td><?php echo mv_h(mv_state_label((int) $item['validate_state'])); ?></td>
                            <td class="mv-time-cell">
                                <?php echo mv_h(mv_time($item['validate_last_send'])); ?>
                                <br/>
                                <span style="color:#999;">到期 <?php echo mv_h(mv_time($item['validate_token_expire'])); ?></span>
                            </td>
                            <td class="mv-actions">
                                <div class="mv-action-group">
                                    <?php if ((int) $item['validate_state'] !== MailValidate_Plugin::STATE_VERIFIED): ?>
                                        <form method="post">
                                            <input type="hidden" name="_" value="<?php echo mv_h($token); ?>"/>
                                            <input type="hidden" name="do" value="resend"/>
                                            <input type="hidden" name="uid" value="<?php echo (int) $item['uid']; ?>"/>
                                            <button type="submit" class="btn btn-s">重发</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="_" value="<?php echo mv_h($token); ?>"/>
                                            <input type="hidden" name="do" value="mark"/>
                                            <input type="hidden" name="uid" value="<?php echo (int) $item['uid']; ?>"/>
                                            <input type="hidden" name="state" value="<?php echo MailValidate_Plugin::STATE_VERIFIED; ?>"/>
                                            <button type="submit" class="btn btn-s">手动通过</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post">
                                            <input type="hidden" name="_" value="<?php echo mv_h($token); ?>"/>
                                            <input type="hidden" name="do" value="mark"/>
                                            <input type="hidden" name="uid" value="<?php echo (int) $item['uid']; ?>"/>
                                            <input type="hidden" name="state" value="<?php echo MailValidate_Plugin::STATE_UNVERIFIED; ?>"/>
                                            <button type="submit" class="btn btn-s">重置</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1): ?>
                    <ul class="typecho-pager">
                        <?php if ($page > 1): ?>
                            <li><a href="<?php echo mv_h(mv_list_url(array('p' => $page - 1))); ?>">&laquo;</a></li>
                        <?php endif; ?>
                        <li class="current"><span><?php echo $page; ?> / <?php echo $totalPages; ?></span></li>
                        <?php if ($page < $totalPages): ?>
                            <li><a href="<?php echo mv_h(mv_list_url(array('p' => $page + 1))); ?>">&raquo;</a></li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="col-mb-12" style="margin-top:26px;">
                <h3>最近日志 <span style="font-weight:normal;color:#888;font-size:13px;">（最近 40 条）</span></h3>
                <table class="typecho-list-table">
                    <thead>
                    <tr>
                        <th style="width:13%;">时间</th>
                        <th style="width:8%;">动作</th>
                        <th style="width:7%;">结果</th>
                        <th style="width:26%;">邮箱</th>
                        <th style="width:12%;">IP</th>
                        <th>说明</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;color:#888;">暂无日志</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo mv_h(date('Y-m-d H:i:s', (int) $log['created'])); ?></td>
                            <td><?php echo mv_h($log['action']); ?></td>
                            <td><?php echo (int) $log['status'] === 1
                                    ? '<span style="color:#2a2;">成功</span>'
                                    : '<span style="color:#c33;">失败</span>'; ?></td>
                            <td><?php echo mv_h($log['mail']); ?></td>
                            <td><?php echo mv_h($log['ip']); ?></td>
                            <td style="word-break:break-all;"><?php echo mv_h($log['message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($hasTable): ?>
                    <form method="post" style="margin-top:10px;">
                        <input type="hidden" name="_" value="<?php echo mv_h($token); ?>"/>
                        <input type="hidden" name="do" value="clear"/>
                        <button type="submit" class="btn btn-s">清理 30 天前日志</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php
include rtrim(__TYPECHO_ROOT_DIR__, '/') . '/admin/copyright.php';
include rtrim(__TYPECHO_ROOT_DIR__, '/') . '/admin/common-js.php';
include rtrim(__TYPECHO_ROOT_DIR__, '/') . '/admin/footer.php';
