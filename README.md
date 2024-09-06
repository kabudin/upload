# upload

> 适配 hyperf 框架的文件上传功能组件整合

## 安装

```shell
composer require bud/upload
```

## 使用方法

```php
<?php
declare(strict_types=1);

namespace App\Controller;

use Bud\Upload\FileUpload;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ResponseInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Annotation\AutoController;

#[AutoController(prefix: "api")]
class TestController extends AbstractController
{
    #[Inject]
    protected FileUpload $upload;
    
    #[Inject]
    protected FileService $service;
    
    /**
     * 单文件上传
     */
    #[RequestMapping(path: "upload", methods: "post")]
    public function upload(): ResponseInterface
    {
        $file = $this->request->file('file');
        $data = $this->upload->upload($file);
        $res = $this->service->create($data);
        return $this->response->success($res->toArray())
    }
    
    /**
     * 分片上传
     */
    #[RequestMapping(path: "chunkUpload", methods: "post")]
    public function chunkUpload(): ResponseInterface
    {
        /**
         * 每个分片接收七个必传参数：’package’、’hash’、’total’、’index’、‘suffix’、’name’、’size’
         * 一个可选参数：’mime_type’
         * 其中分片文件对象必须以’package’为key传递
         */
        $data = $this->request->post();
        $data['package'] = $this->request->file('package');
        $data = $this->upload->chunkUpload($data);
        $res = $this->service->create($data);
        return $this->response->success($res->toArray())
    }
    
    /**
     * 生成缩略图
     */
    #[RequestMapping(path: "thumbnail/{id}", methods: "get")]
    public function thumbnail(int $id): ResponseInterface
    {
        $width  = $this->request->query('width', 100);
        $height  = $this->request->query('height', 100);
        $res = $this->service->find($id);
        // 此处可以根据业务需求判断当前访问文件大小、格式、以及是否需要鉴权，进行相应的拦截处理
        $Binary = $this->upload->makeThumb($res->url,$res->storage,$width,$height);
        return $this->response->withHeader('content-type', $res->mime_type)
            ->withBody(new SwooleStream($Binary));
    }
}
```
## 更多用法参考 Bud\Upload\FileUpload 类
