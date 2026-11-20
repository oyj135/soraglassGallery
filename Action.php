<?php
namespace TypechoPlugin\SoraglassGallery;

final class Action extends \Widget\Base implements \Widget\ActionInterface
{
    public function action()
    {
        $this->response->setContentType('application/json')->setHeader('Cache-Control', 'no-store');
        try {
            $op = (string) $this->request->get('op');
            if (!Plugin::enabled()) { throw new \RuntimeException('画廊插件未启用', 403); }
            if (in_array($op, ['gallery', 'gallery-photos', 'gallery-search'], true)) {
                if (!$this->request->isGet()) { throw new \RuntimeException('公开数据仅允许 GET 请求', 405); }
                $catalog = (new Repository())->catalog(); $page = max(1, (int) $this->request->get('page'));
                $query = (string) ($this->request->get('q') ?? '');
                $data = $op === 'gallery' ? $catalog->albums($page, $query, (string) ($this->request->get('album') ?? '')) : $catalog->photos($op === 'gallery-photos' ? (string) $this->request->get('album') : '', $page, $op === 'gallery-search' ? $query : '');
                echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit;
            }
            if (!$this->user->pass('administrator', true)) { throw new \RuntimeException('需要管理员登录并启用画廊插件', 403); }
            $token = (string) $this->request->get('_');
            if (!hash_equals($this->security->getToken('SoraglassGallery/admin'), $token) && !hash_equals($this->security->getToken($this->request->getReferer()), $token)) { throw new \RuntimeException('安全验证过期，请刷新后台页面后重试', 403); }
            if (!in_array($op, ['list', 'status', 'config-status'], true) && !$this->request->isPost()) { throw new \RuntimeException('操作需要 POST 请求', 405); }
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            $repo = new Repository();
            $input = $_POST;
            $data = [];
            switch ($op) {
                case 'config': $data = Settings::save($input); break;
                case 'config-status': $data = Settings::status(); break;
                case 'credential': $data = ['value' => Settings::credential((string) ($input['field'] ?? ''))]; break;
                case 'list':
                    $data = $repo->photos(max(1, (int) $this->request->get('page')), (string) ($this->request->get('album') ?? '')) + ['albums' => $repo->albums()];
                    break;
                case 'album': $data = $repo->saveAlbum($input); break;
                case 'photo': $repo->savePhoto($input); break;
                case 'delete': $data = (new Removal($repo, Settings::read()))->delete((string) ($input['id'] ?? ''), !empty($input['purge'])); break;
                case 'status': $data = $repo->uploadStatus((string) $this->request->get('task')); break;
                case 'prepare': case 'upload': case 'recover':
                    $config = Settings::cloud();
                    if ($op === 'recover') { $config['timeout'] = min(90, (int) $config['timeout']); $config['inspection'] = true; }
                    $service = new Upload($repo, $config, Storage\Registry::create($config));
                    if ($op === 'prepare') { $data = $service->prepare($input, (int) $this->user->uid); }
                    elseif ($op === 'recover') { $service->recover((string) ($input['task'] ?? '')); }
                    else {
                        if (!isset($_FILES['photo']) || is_array($_FILES['photo']['error']) || (int) $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
                            throw new \InvalidArgumentException('服务器未收到完整照片，请检查 PHP upload_max_filesize / post_max_size、临时目录和 Nginx client_max_body_size');
                        }
                        $file = $_FILES['photo'];
                        if (is_array($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) { throw new \InvalidArgumentException('上传文件无效'); }
                        $service->send((string) ($input['task'] ?? ''), $file['tmp_name']);
                    }
                    if ($op !== 'prepare') { $data = $repo->uploadStatus((string) ($input['task'] ?? '')); }
                    break;
                default: throw new \InvalidArgumentException('未知画廊操作');
            }
            echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\Throwable $e) {
            $this->response->setStatus(in_array($e->getCode(), [403, 405], true) ? $e->getCode() : 400);
            echo json_encode(['ok' => false, 'error' => Upload::error($e)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        exit;
    }
}
