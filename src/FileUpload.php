<?php
declare(strict_types=1);

namespace Bud\Upload;

use Bud\Upload\Exception\UploadException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Filesystem\FilesystemFactory;
use Hyperf\HttpMessage\Upload\UploadedFile;
use Hyperf\Support\Filesystem\FileNotFoundException;
use Hyperf\Support\Filesystem\Filesystem;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use JetBrains\PhpStorm\ArrayShape;
use League\Flysystem\FilesystemException;

class FileUpload
{
    protected string $filePath;

    public function __construct(
        protected ValidatorFactoryInterface $validationFactory,
        protected ConfigInterface           $config,
        protected FilesystemFactory         $factory,
        protected Filesystem                $filesystem,
    )
    {
        $this->filePath = '/upload/' . date('Ymd') . '/';
    }

    /**
     * 文件上传
     * @param \Hyperf\HttpMessage\Upload\UploadedFile $file
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @return array
     */
    #[ArrayShape(['hash' => "false|string", 'mime_type' => "string", 'storage' => "string", 'storage_path' => "string", 'original_name' => "null|string", 'suffix' => "string", 'size_byte' => "int", 'url' => "string"])]
    public function upload(UploadedFile $file, ?string $storage = null): array
    {
        $this->checkImg($file);
        $hash = md5_file($file->getPathname());
        $storage = $storage ?? $this->config->get('file.default', 'local');
        $location = $this->filePath . md5($hash) . '.' . $file->getExtension();
        try {
            $this->getFilesystem($storage)->writeStream($location, $file->getStream()->detach());
        } catch (FilesystemException $e) {
            throw new UploadException($e->getMessage(), $e->getCode(), $e);
        }
        // 返回文件信息
        return [
            'hash' => $hash,   // hash值
            'mime_type' => $file->getMimeType(), // 文件类型
            'storage' => $storage,  // 存储方式
            'storage_path' => $this->filePath,  // 存储路劲
            'original_name' => $file->getClientFilename(),  // 原始文件名称
            'suffix' => $file->getExtension(), // 文件后缀
            'size_byte' => $file->getSize(), // 文件大小字节数
            'url' => $location,  // 访问路径
        ];
    }

    /**
     * 分片上传
     * @param array $data 分片数据包
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @return array
     */
    #[ArrayShape(['code' => "int", 'message' => "string", 'data' => "array"])]
    public function chunkUpload(array $data, ?string $storage = null): array
    {
        $this->verifyShardData($data);
        /* @var UploadedFile $uploadFile */
        $uploadFile = $data['package'];
        $path = BASE_PATH . "/runtime/chunk/{$data['hash']}/";
        $chunkName = "$path{$data['index']}.chunk";
        $storage = $storage ?? $this->config->get('file.default', 'local');
        $this->filesystem->isDirectory($path) || $this->filesystem->makeDirectory($path, 0755, true, true);
        $next_chunk = $this->getNextChunk($path, (int)$data['index'], (int)$data['total']);
        if ($this->filesystem->exists($chunkName) && $next_chunk) {
            return $this->buildResponse([
                'current_chunk' => (int)$data['index'],
                'total_chunk' => (int)$data['total'],
                'next_chunk' => $next_chunk,
                'percent' => round($data['index'] / $data['total'], 2)
            ], 201);
        }
        !$this->filesystem->exists($chunkName) && $uploadFile->moveTo($chunkName);
        if ($data['index'] === $data['total'] || !$next_chunk) {
            $extension = strtolower($data['suffix']);
            $mergePath = "{$path}merge.$extension";
            for ($i = 0; $i <= $data['total']; $i++) {
                $chunkFile = "$path{$i}.chunk";
                try {
                    $this->filesystem->append($mergePath, $this->filesystem->get($chunkFile));  // 追加写入文件内容
                } catch (FileNotFoundException) {
                    throw new UploadException('分片文件丢失，上传失败', 500);
                }
            }
            $location = $this->filePath . md5($data['hash']) . '.' . $extension;
            try {
                $this->getFilesystem($storage)->write($location, $this->filesystem->sharedGet($mergePath));
            } catch (FilesystemException $e) {
                $this->filesystem->delete($mergePath); // 上传失败删除已合并的文件
                throw new UploadException($e->getMessage(), $e->getCode(), $e);
            }
            $this->filesystem->deleteDirectory($path);    // 上传成功删除整个分片目录
            return $this->buildResponse([
                'hash' => $data['hash'],   // hash值
                'mime_type' => $data['mime_type'], // 文件类型
                'storage' => $storage,  // 存储方式
                'storage_path' => $location,  // 存储路劲
                'original_name' => $data['name'],  // 原始文件名称
                'suffix' => $extension, // 文件后缀
                'size_byte' => $data['size'], // 文件大小字节数
                'url' => $location,  // 访问路径
            ], 200);
        }
        return $this->buildResponse([
            'current_chunk' => (int)$data['index'],
            'total_chunk' => (int)$data['total'],
            'next_chunk' => $next_chunk,
            'percent' => round($data['index'] / $data['total'], 2)
        ], 201);
    }

    /**
     * 保存网络图片，仅支持 jpg|png
     * @param string $url 网络图片地址
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @return array
     */
    #[ArrayShape(['hash' => "false|string", 'mime_type' => "string", 'storage' => "string", 'storage_path' => "string", 'original_name' => "null|string", 'suffix' => "string", 'size_byte' => "int", 'url' => "string"])]
    public function saveNetworkImage(string $url, ?string $storage = null): array
    {
        $client = new Client();
        try {
            $response = $client->get($url);
        } catch (GuzzleException $e) {
            throw new UploadException($e->getMessage(), $e->getCode(), $e);
        }
        $mime_type = $response->getHeader('content-type')[0];
        if (!in_array($mime_type, ['image/jpeg', 'image/png'])) {
            throw new UploadException("Only supports image/jpeg or image/png given:$mime_type", 500);
        }
        $suffix = $mime_type == 'image/jpeg' ? 'jpg' : 'png';
        $path = BASE_PATH . "/runtime/network/";
        $original_name = md5($response->getBody()->getContents()) . '.' . $suffix;
        $temp = $path . $original_name;
        $this->filesystem->isDirectory($path) || $this->filesystem->makeDirectory($path);
        $this->filesystem->put($temp, $response->getBody());
        $hash = md5_file($temp);
        $size = filesize($temp);
        $storage = $storage ?? $this->config->get('file.default', 'local');
        $location = $this->filePath . md5($hash) . '.' . $suffix;
        try {
            $this->getFilesystem($storage)->write($location, $this->filesystem->sharedGet($temp));
        } catch (FilesystemException $e) {
            $this->filesystem->delete($temp);
            throw new UploadException($e->getMessage(), $e->getCode(), $e);
        }
        $this->filesystem->delete($temp);
        return [
            'hash' => $hash,   // hash值
            'mime_type' => $mime_type, // 文件类型
            'storage' => $storage,  // 存储方式
            'storage_path' => $this->filePath,  // 存储路劲
            'original_name' => $original_name,  // 原始文件名称
            'suffix' => $suffix, // 文件后缀
            'size_byte' => $size, // 文件大小字节数
            'url' => $location,  // 访问路径
        ];
    }

    /**
     * 创建目录
     * @param string $location
     * @param string|null $storage
     * @return bool
     */
    public function createDirectory(string $location, ?string $storage = null): bool
    {
        $storage = $storage ?? $this->config->get('file.default', 'local');
        try {
            $this->getFilesystem($storage)->createDirectory($location);
        } catch (FilesystemException) {
            return false;
        }
        return true;
    }

    /**
     * 获取目录内容
     * @param string $path
     * @param bool $isChildren 是否递归获取子目录
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @return \League\Flysystem\DirectoryListing|null
     */
    public function getDirectory(string $path, bool $isChildren, ?string $storage = null): ?\League\Flysystem\DirectoryListing
    {
        $storage = $storage ?? $this->config->get('file.default', 'local');
        try {
            $contents = $this->getFilesystem($storage)->listContents($path, $isChildren);
        } catch (FilesystemException) {
            $contents = null;
        }
        return $contents;
    }

    /**
     * 读取文件内容
     * @param string $location 文件存储url
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @return string
     */
    public function read(string $location, ?string $storage = null): string
    {
        $storage = $storage ?? $this->config->get('file.default', 'local');
        try {
            return $this->getFilesystem($storage)->read($location);
        } catch (FilesystemException $e) {
            throw new UploadException($e->getMessage(), 500);
        }
    }

    /**
     * 返回文件的缩略图
     * @param string $location 图片地址
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @param int $width 缩略图宽度
     * @param int $height 缩略图高度
     * @return string 二进制图片
     */
    public function makeThumb(string $location, ?string $storage = null, int $width = 100, int $height = 100): string
    {
        $binaryFiles = imagecreatefromstring($this->read($location, $storage));
        // 获取原始图片的宽度和高度
        $originalWidth = imagesx($binaryFiles);
        $originalHeight = imagesy($binaryFiles);
        // 计算原始图片和缩略图的宽高比
        $originalRatio = $originalWidth / $originalHeight;
        $thumbnailRatio = $width / $height;
        // 根据原始图片和缩略图的宽高比调整缩略图的尺寸
        if ($originalRatio > $thumbnailRatio) {
            $height = (int)round($width / $originalRatio); // 如果原始图片比缩略图更宽，则根据宽度比例调整缩略图高度
        } else {
            $width = (int)round($height * $originalRatio); // 如果原始图片比缩略图更高，则根据高度比例调整缩略图宽度
        }
        // 创建缩略图资源
        $thumbnailImage = imagecreatetruecolor($width, $height);
        // 将原始图片缩放到缩略图尺寸
        imagecopyresampled($thumbnailImage, $binaryFiles, 0, 0, 0, 0, $width, $height, $originalWidth, $originalHeight);
        ob_start(); // 开启输出缓冲区
        imagejpeg($thumbnailImage, null, 100);
        imagedestroy($binaryFiles); // 释放资源
        $thumbnailContent = ob_get_clean(); // 获取并清空缓冲区内容
        imagedestroy($thumbnailImage); // 释放资源
        return $thumbnailContent;
    }

    /**
     * 获取存储模式
     * @param string $storage
     * @return \League\Flysystem\Filesystem
     */
    public function getFilesystem(string $storage): \League\Flysystem\Filesystem
    {
        return $this->factory->get($storage);
    }

    /**
     * 校验分片数据包
     * @param array $data
     */
    protected function verifyShardData(array $data): void
    {
        $rules = [
            'package' => 'required|file',
            'hash' => 'required',
            'total' => 'required',
            'index' => 'required',
            'suffix' => 'required',
            'name' => 'required',
            'size' => 'required',
            'mime_type' => 'required',
        ];
        $validator = $this->validationFactory->make($data, $rules);
        if ($validator->fails()) {
            throw new UploadException($validator->errors()->first(), 422);
        }
    }

    /**
     * 遍历查找下一个未上传的分片
     * @param string $chunkPath
     * @param int $current_chunk
     * @param int $total
     * @return int|null
     */
    protected function getNextChunk(string $chunkPath, int $current_chunk, int $total): ?int
    {
        for ($i = $current_chunk + 1; $i <= $total; $i++) {
            $chunkFile = "$chunkPath{$i}.chunk";
            if (!file_exists($chunkFile)) {
                return $i;
            }
        }
        return null;
    }

    /**
     * 构建分片上传响应格式
     * @param array $data
     * @param int $code
     * @return array
     */
    #[ArrayShape(['code' => "int", 'message' => "string", 'data' => "array"])]
    protected function buildResponse(array $data, int $code): array
    {
        return [
            'code' => $code,
            'message' => 'success',
            'data' => $data,
        ];
    }

    /**
     * 检查图片是否存在木马
     * @param \Hyperf\HttpMessage\Upload\UploadedFile $file
     */
    protected function checkImg(UploadedFile $file)
    {
        // 检查 MIME 类型是否为图片类型
        if (!str_starts_with($file->getMimeType(), 'image/')) {
            return;
        }
        // 读取上传文件的内容
        $fileContent = file_get_contents($file->getRealPath());
        $fileSize = strlen($fileContent);
        // 定义正则表达式模式
        $pattern = "/(3c25.*?28.*?29.*?253e)|(3c3f.*?28.*?29.*?3f3e)|(3C534352495054)|(2F5343524950543E)|(3C736372697074)|(2F7363726970743E)/is";
        if ($fileSize > 512) {
            // 取头和尾
            $hexCode = bin2hex(substr($fileContent, 0, 512));
            $hexCode .= bin2hex(substr($fileContent, $fileSize - 512, 512));
        } else {
            // 取全部
            $hexCode = bin2hex($fileContent);
        }
        if (preg_match($pattern, $hexCode)) throw new UploadException('非法图片上传！');
    }
}
