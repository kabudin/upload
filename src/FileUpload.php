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
use Hyperf\Support\MimeTypeExtensionGuesser;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use JetBrains\PhpStorm\ArrayShape;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FilesystemException;
use function Hyperf\Support\make;

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
     * @param UploadedFile $file
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @return array
     */
    #[ArrayShape(['hash' => "false|string", 'mime_type' => "string", 'storage' => "string", 'storage_path' => "string", 'original_name' => "null|string", 'object_name' => "string", 'suffix' => "string", 'size_byte' => "int", 'url' => "string"])]
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
            'object_name' => md5($hash) . '.' . $file->getExtension(),  // 新文件名称
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
        $this->filesystem->isDirectory($path) || $this->filesystem->makeDirectory($path, 0755, true, true);
        $next_chunk = $this->getNextChunk($path, (int)$data['index'], (int)$data['total']);
        $percent = $next_chunk / $data['total'];
        if ($this->filesystem->exists($chunkName) && $next_chunk) {
            return $this->buildResponse([
                'current_chunk' => (int)$data['index'],
                'total_chunk' => (int)$data['total'],
                'next_chunk' => $next_chunk,
                'percent' => round($percent * 100, 2)
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
            $storage = $storage ?? $this->config->get('file.default', 'local');
            $location = $this->filePath . md5($data['hash']) . '.' . $extension;
            try {
                $this->getFilesystem($storage)->write($location, $this->filesystem->sharedGet($mergePath));
            } catch (FilesystemException $e) {
                $this->filesystem->delete($mergePath); // 上传失败删除已合并的文件
                throw new UploadException($e->getMessage(), $e->getCode(), $e);
            }
            $this->filesystem->deleteDirectory($path);    // 上传成功删除整个分片目录
            $mime_type = $data['mime_type'] ?? make(MimeTypeExtensionGuesser::class)->guessMimeType($extension);
            return $this->buildResponse([
                'hash' => $data['hash'],   // hash值
                'mime_type' => $mime_type, // 文件类型
                'storage' => $storage,  // 存储方式
                'storage_path' => $this->filePath,  // 存储路劲
                'original_name' => $data['name'],  // 原始文件名称
                'object_name' => md5($data['hash']) . '.' . $extension,  // 新文件名称
                'suffix' => $extension, // 文件后缀
                'size_byte' => (int)$data['size'], // 文件大小字节数
                'url' => $location,  // 访问路径
            ], 200);
        }
        return $this->buildResponse([
            'current_chunk' => (int)$data['index'],
            'total_chunk' => (int)$data['total'],
            'next_chunk' => $next_chunk,
            'percent' => round($percent * 100, 2)
        ], 201);
    }

    /**
     * 保存网络文件
     * @param string $url 网络文件地址
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @return array
     */
    #[ArrayShape(['hash' => "false|string", 'mime_type' => "string", 'storage' => "string", 'storage_path' => "string", 'original_name' => "null|string", 'object_name' => "string", 'suffix' => "string", 'size_byte' => "int", 'url' => "string"])]
    public function saveNetworkImage(string $url, ?string $storage = null): array
    {
        $client = new Client();
        try {
            $response = $client->get($url);
        } catch (GuzzleException $e) {
            throw new UploadException($e->getMessage(), $e->getCode(), $e);
        }

        $mime_type = $response->getHeader('content-type')[0] ?? null;
        if ($mime_type) {
            $suffix = make(MimeTypeExtensionGuesser::class)->guessExtension($mime_type);
        } else {
            $suffix = pathinfo($url, PATHINFO_EXTENSION);
            $mime_type = make(MimeTypeExtensionGuesser::class)->guessMimeType($suffix);
        }
        if (empty($suffix) || empty($mime_type)) {
            throw new UploadException("File type parsing failed", 500);
        }
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
            'object_name' => md5($hash) . '.' . $suffix,  // 新文件名称
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
     * @return DirectoryListing|null
     */
    public function getDirectory(string $path, bool $isChildren, ?string $storage = null): ?DirectoryListing
    {
        try {
            $contents = $this->getFilesystem($storage)->listContents($path, $isChildren);
        } catch (FilesystemException) {
            $contents = null;
        }
        return $contents;
    }

    /**
     * 删除文件
     * @param string $location 文件存储url
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     */
    public function delete(string $location, ?string $storage = null)
    {
        try {
            $this->getFilesystem($storage)->delete($location);
        } catch (FilesystemException $e) {
            throw new UploadException($e->getMessage(), 500);
        }
    }

    /**
     * 读取文件内容
     * @param string $location 文件存储url
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @return string
     */
    public function read(string $location, ?string $storage = null): string
    {
        try {
            return $this->getFilesystem($storage)->read($location);
        } catch (FilesystemException $e) {
            throw new UploadException($e->getMessage(), 500);
        }
    }

    /**
     * 读取文件资源句柄
     * @param string $location 文件存储url
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @return resource
     */
    public function readStream(string $location, ?string $storage = null)
    {
        try {
            return $this->getFilesystem($storage)->readStream($location);
        } catch (FilesystemException $e) {
            throw new UploadException($e->getMessage(), 500);
        }
    }

    /**
     * 读取文件字节，注意：该方法不会关闭文件资源句柄
     * @param resource $resource 文件资源句柄
     * @param int $start 开始位置 默认0。大于0时，会移动文件指针到指定位置
     * @param int $end 结束位置 默认0。大于0时，读取指定长度的字节。小于等于0时，读取到文件末尾
     * @return string
     */
    public function readBytes($resource, int $start = 0, int $end = 0): string
    {
        if (!is_resource($resource)) {
            throw new \RuntimeException('Stream is not valid');
        }
        if ($start > 0) {
            fseek($resource, $start);
        }
        if ($end > 0) {
            $length = $end - $start;
            return fread($resource, $length);
        }
        return stream_get_contents($resource);
    }

    /**
     * 返回文件的缩略图
     * @param string $location 图片地址
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @param int $width 缩略图宽度
     * @param int|null $height 缩略图高度
     * @return string 二进制图片
     */
    public function makeThumb(string $location, ?string $storage = null, int $width = 100, int $height = null): string
    {
        $height = $height ?? $width;
        try {
            $binaryFiles = imagecreatefromstring($this->read($location, $storage));
        } catch (\Throwable) {
            throw new UploadException('生成缩略失败！', 500);
        }
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
     * @param string|null $storage 存储模式，默认获取配置文件中的默认存储模式||local
     * @return \League\Flysystem\Filesystem
     */
    public function getFilesystem(?string $storage = null): \League\Flysystem\Filesystem
    {
        $storage = $storage ?? $this->config->get('file.default', 'local');
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
        ];
        $message = [
            'package.required' => 'The :attribute field is required.',
            'package.file' => 'The :attribute must be a file.',
            'hash.required' => 'The :attribute field is required.',
            'total.required' => 'The :attribute field is required.',
            'index.required' => 'The :attribute field is required.',
            'suffix.required' => 'The :attribute field is required.',
            'name.required' => 'The :attribute field is required.',
            'size.required' => 'The :attribute field is required.',
        ];
        $validator = $this->validationFactory->make($data, $rules, $message);
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
     * @param UploadedFile $file
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
