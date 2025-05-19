<?php namespace Phpcmf\ThirdParty\Storage;

// AWS S3存储
class S3 {

    protected $data;
    protected $filename;
    protected $filepath;
    protected $attachment;
    protected $watermark;

    private $accessKey;
    private $secretKey;
    private $region;
    private $bucket;
    private $endpoint;

    public function init($attachment, $filename) {
        $this->filename = trim($filename, DIRECTORY_SEPARATOR);
        $this->filepath = dirname($filename);
        $this->filepath == '.' && $this->filepath = '';
        $this->attachment = $attachment;

        $this->accessKey = trim($attachment['value']['key']);
        $this->secretKey = trim($attachment['value']['secret']);
        $this->region = trim($attachment['value']['region']);
        $this->bucket = trim($attachment['value']['bucket']);
        $this->endpoint = !empty($attachment['value']['endpoint']) ? trim($attachment['value']['endpoint']) : null;

        require_once FCPATH.'ThirdParty/Storage/S3/aws/aws-autoloader.php';
        return $this;
    }

    private function _client() {
        $args = [
            'version' => 'latest',
            'region' => $this->region,
            'credentials' => [
                'key' => $this->accessKey,
                'secret' => $this->secretKey
            ],
            'http' => [
                'verify' => true
            ]
        ];
        if ($this->endpoint) {
            $args['endpoint'] = $this->endpoint;
        }
        return new \Aws\S3\S3Client($args);
    }

    public function upload($type, $data, $watermark) {
        $this->data = $data;
        $this->watermark = $watermark;

        // 创建临时文件
        $srcPath = WRITEPATH.'attach/'.SYS_TIME.'-'.str_replace([DIRECTORY_SEPARATOR, '/'], '-', $this->filename);
        if ($type) {
            if (!(dr_move_uploaded_file($this->data, $srcPath) || !is_file($srcPath))) {
                return dr_return_data(0, dr_lang('文件移动失败'));
            }
            $file_size = filesize($srcPath);
        } else {
            $file_size = file_put_contents($srcPath, $this->data);
            if (!$file_size || !is_file($srcPath)) {
                return dr_return_data(0, dr_lang('文件创建失败'));
            }
        }

        $info = [];
        if (dr_is_image($srcPath)) {
            // 图片压缩
            if ($this->attachment['image_reduce']) {
                \Phpcmf\Service::L('image')->reduce($srcPath, $this->attachment['image_reduce']);
            }
            // 水印处理
            if ($this->watermark) {
                $config = \Phpcmf\Service::C()->get_cache('site', SITE_ID, 'watermark');
                $config['source_image'] = $srcPath;
                $config['dynamic_output'] = false;
                \Phpcmf\Service::L('Image')->watermark($config);
            }
            // 获取图片尺寸
            $img = getimagesize($srcPath);
            if (!$img) {
                @unlink($srcPath);
                return dr_return_data(0, dr_lang('此图片不是一张可用的图片'));
            }
            $info = [ 'width' => $img[0], 'height' => $img[1] ];
        }

        try {
            $client = $this->_client();
            $object = isset($this->attachment['value']['path']) && $this->attachment['value']['path'] 
                ? trim($this->attachment['value']['path'], '/').'/'.$this->filename 
                : $this->filename;

            $client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $object,
                'Body' => fopen($srcPath, 'rb'),
                'ContentType' => mime_content_type($srcPath)
            ]);

            $md5 = md5_file($srcPath);
            @unlink($srcPath);
            return dr_return_data(1, 'ok', [
                'url' => $this->attachment['url'].$this->filename,
                'md5' => $md5,
                'size' => $file_size,
                'info' => $info
            ]);
        } catch (\Exception $e) {
            @unlink($srcPath);
            return dr_return_data(0, $e->getMessage());
        }
    }

    public function delete() {
        try {
            $client = $this->_client();
            $object = isset($this->attachment['value']['path']) && $this->attachment['value']['path']
                ? trim($this->attachment['value']['path'], '/').'/'.$this->filename
                : $this->filename;

            $client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $object
            ]);
            return;
        } catch (\Exception $e) {
            log_message('error', 'S3存储删除失败：'.$e->getMessage());
        }
    }
}
