# Laravel-flysystem-oss

<p align="center">
    <a href="https://packagist.org/packages/larva/laravel-flysystem-oss"><img src="https://poser.pugx.org/larva/laravel-flysystem-oss/v/stable" alt="Stable Version"></a>
    <a href="https://packagist.org/packages/larva/laravel-flysystem-oss"><img src="https://poser.pugx.org/larva/laravel-flysystem-oss/downloads" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/larva/laravel-flysystem-oss"><img src="https://poser.pugx.org/larva/laravel-flysystem-oss/license" alt="License"></a>
</p>

适用于 Laravel 的阿里云 OSS 适配器，完整支持阿里云 OSS 所有方法和操作。

## 安装

```bash
composer require imnpc/laravel-flysystem-oss -vv
```

修改配置文件: `config/filesystems.php`

添加一个磁盘配置

```php
    'oss' => [
        'driver' => 'oss',
        'access_id' => env('OSS_ACCESS_KEY_ID'),
        'access_key' => env('OSS_ACCESS_KEY_SECRET'),
        'bucket' => env('OSS_BUCKET'),
        'endpoint' => env('OSS_ENDPOINT'), // 不要用CName,经过测试，官方SDK实现不靠谱
        'url' => env('OSS_DOMAIN'), // CNAME 写这里，可以是域名绑定或者CDN地址 如 https://www.bbb.com 末尾不要斜杠
        'root' => env('OSS_ROOT', ''), // 这个文件路径前缀，如果上传的内容全部在子目录就填写，否则为空
        'security_token' => null,
        'proxy' => null,
        'timeout' => 3600,
        'ssl' => env('OSS_SSL', false),
    ],

```
在 .env 文件里面添加

```php

# 阿里云 OSS
OSS_ACCESS_KEY_ID=
OSS_ACCESS_KEY_SECRET=
OSS_BUCKET=
OSS_ENDPOINT=
OSS_DOMAIN=
OSS_ROOT=
OSS_SSL=

```
修改默认存储驱动

```php
    'default' => 'oss'
```

## 使用

参见 [Laravel wiki](https://laravel.com/docs/9.x/filesystem)
