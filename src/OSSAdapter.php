<?php
/**
 * @copyright Copyright (c) 2018 Larva Information Technology Co., Ltd.
 * @link http://www.larvacent.com/
 * @license http://www.larvacent.com/license/
 */

namespace Larva\Flysystem\Aliyun;

use Carbon\Carbon;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\StreamedTrait;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
use OSS\Core\OssException;
use OSS\OssClient;

/**
 * 阿里云适配器
 */
class OSSAdapter extends AbstractAdapter
{
    use StreamedTrait;

    /**
     * @var OssClient
     */
    protected $client;

    /**
     * @var array
     */
    protected $config = [];

    /**
     * Adapter constructor.
     *
     * @param OssClient $client
     * @param array $config
     */
    public function __construct(OssClient $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
        $this->setPathPrefix($config['prefix']);
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        $object = $this->applyPathPrefix($path);
        $options = $this->prepareUploadConfig($config);
        if (!isset($options[OssClient::OSS_LENGTH])) {
            $options[OssClient::OSS_LENGTH] = Util::contentSize($contents);
        }
        if (!isset($options[OssClient::OSS_CONTENT_TYPE])) {
            $options[OssClient::OSS_CONTENT_TYPE] = Util::guessMimeType($path, $contents);
        }
        try {
            $this->client->putObject($this->getBucket(), $object, $contents, $options);
        } catch (OssException $e) {
            return false;
        }
        $type = 'file';
        $result = compact('type', 'path', 'contents');
        $result['mimetype'] = $options[OssClient::OSS_CONTENT_TYPE];
        $result['size'] = $options[OssClient::OSS_LENGTH];
        return $result;
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     * @return bool
     */
    public function rename($path, $newpath)
    {
        if (!$this->copy($path, $newpath)) {
            return false;
        }
        return $this->delete($path);
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $object = $this->applyPathPrefix($path);
        $newobject = $this->applyPathPrefix($newpath);
        try {
            $this->client->copyObject($this->getBucket(), $object, $this->getBucket(), $newobject);
        } catch (OssException $e) {
            return false;
        }
        return true;
    }

    /**
     * Delete a file.
     *
     * @param string $path
     * @return bool
     */
    public function delete($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $this->client->deleteObject($this->getBucket(), $object);
        } catch (OssException $e) {
            return false;
        }
        return true;
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     * @return bool
     */
    public function deleteDir($dirname)
    {
        try {
            $list = $this->listContents($dirname, true);
            $objects = [];
            foreach ($list as $val) {
                if ($val['type'] === 'file') {
                    $objects[] = $this->applyPathPrefix($val['path']);
                } else {
                    $objects[] = $this->applyPathPrefix($val['path']) . '/';
                }
            }
            $this->client->deleteObjects($this->getBucket(), $objects);
        } catch (OssException $e) {
            return false;
        }
        return true;
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        $object = $this->applyPathPrefix($dirname);
        $options = $this->prepareUploadConfig($config);
        try {
            $this->client->createObjectDir($this->getBucket(), $object, $options);
        } catch (OssException $e) {
            return false;
        }
        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {
        $location = $this->applyPathPrefix($path);
        try {
            $this->client->putObjectAcl($this->getBucket(), $location, $this->normalizeVisibility($visibility));
        } catch (OssException $e) {
            return false;
        }
        return $this->getMetadata($path);
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     * @return array|bool|null
     */
    public function has($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $exists = $this->client->doesObjectExist($this->getBucket(), $object);
        } catch (OssException $e) {
            return false;
        }
        return $exists;
    }

    /**
     * Read a file.
     *
     * @param string $path
     * @return array|false
     */
    public function read($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $contents = $this->client->getObject($this->getBucket(), $object);
        } catch (OssException $e) {
            return false;
        }
        return compact('contents', 'path');
    }

    /**
     * 获取对象访问Url
     * @param string $path
     * @return string
     * @throws OssException
     */
    public function getUrl($path)
    {
        $location = $this->applyPathPrefix($path);
        if (isset($this->config['url']) && !empty($this->config['url'])) {
            return $this->config['url'] . '/' . ltrim($location, '/');
        } else {
            $visibility = $this->getVisibility($path);
            if ($visibility && $visibility['visibility'] == 'private') {
                return $this->getTemporaryUrl($path, Carbon::now()->addMinutes(5), []);
            }
            $scheme = $this->config['ssl'] ? 'https://' : 'http://';
            return $scheme . $this->getBucket() . '.' . $this->config['endpoint'] . '/' . ltrim($location, '/');
        }
    }

    /**
     * 获取文件临时访问路径
     * @param $path
     * @param $expiration
     * @param $options
     * @return string
     * @throws \OSS\Core\OssException
     */
    public function getTemporaryUrl($path, \DateTimeInterface $expiration, array $options = [])
    {
        $location = $this->applyPathPrefix($path);
        $timeout = $expiration->getTimestamp() - time();
        return $this->client->signUrl($this->getBucket(), $location, $timeout, OssClient::OSS_HTTP_GET, $options);
    }

    /**
 * List contents of a directory.
 *
 * @param string $directory
 * @param bool $recursive
 * @return array
 * @throws OssException
 */
    public function listContents($directory = '', $recursive = false)
    {
        $directory = rtrim($this->applyPathPrefix($directory), '\\/');
        if ($directory) $directory .= '/';
        $delimiter = '/';
        $nextMarker = '';
        $maxKeys = 1000;
        $options = [
            'delimiter' => $delimiter,
            'prefix' => $directory,
            'max-keys' => $maxKeys,
            'marker' => $nextMarker,
        ];
        $listObjectInfo = $this->client->listObjects($this->getBucket(), $options);
        $objectList = $listObjectInfo->getObjectList(); // 文件列表
        $prefixList = $listObjectInfo->getPrefixList(); // 目录列表
        $result = [];
        foreach ($objectList as $objectInfo) {
            if ($objectInfo->getSize() === 0 && $directory === $objectInfo->getKey()) {
                $result[] = [
                    'type' => 'dir',
                    'path' => $this->removePathPrefix(rtrim($objectInfo->getKey(), '/')),
                    'timestamp' => strtotime($objectInfo->getLastModified()),
                ];
                continue;
            }
            $result[] = [
                'type' => 'file',
                'path' => $this->removePathPrefix($objectInfo->getKey()),
                'timestamp' => strtotime($objectInfo->getLastModified()),
                'size' => $objectInfo->getSize(),
            ];
        }
        foreach ($prefixList as $prefixInfo) {
            if ($recursive) {
                $next = $this->listContents($this->removePathPrefix($prefixInfo->getPrefix()), $recursive);
                $result = array_merge($result, $next);
            } else {
                $result[] = [
                    'type' => 'dir',
                    'path' => $this->removePathPrefix(rtrim($prefixInfo->getPrefix(), '/')),
                    'timestamp' => 0,
                ];
            }
        }
        return $result;
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     * @return array|false
     */
    public function getMetadata($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $result = $this->client->getObjectMeta($this->getBucket(), $object);
        } catch (OssException $e) {
            return false;
        }
        return [
            'type' => 'file',
            'dirname' => Util::dirname($path),
            'path' => $path,
            'timestamp' => strtotime($result['last-modified']),
            'mimetype' => $result['content-type'],
            'size' => $result['content-length'],
        ];
    }

    /**
     * Get the size of a file.
     *
     * @param string $path
     * @return array|false
     */
    public function getSize($path)
    {
        $meta = $this->getMetadata($path);
        return isset($meta['size'])
            ? ['size' => $meta['size']] : false;
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     * @return array|false
     */
    public function getMimetype($path)
    {
        $meta = $this->getMetadata($path);
        return isset($meta['mimetype'])
            ? ['mimetype' => $meta['mimetype']] : false;
    }

    /**
     * Get the last modified time of a file as a timestamp.
     *
     * @param string $path
     * @return array|false
     */
    public function getTimestamp($path)
    {
        $meta = $this->getMetadata($path);
        return isset($meta['timestamp'])
            ? ['timestamp' => strtotime($meta['timestamp'])] : false;
    }

    /**
     * Get the visibility of a file.
     *
     * @param string $path
     * @return array|false
     */
    public function getVisibility($path)
    {
        $location = $this->applyPathPrefix($path);
        try {
            $response = $this->client->getObjectAcl($this->getBucket(), $location);
        } catch (OssException $e) {
            return false;
        }
        return ['visibility' => $response];
    }

    /**
     * @param Config $config
     *
     * @return array
     */
    private function prepareUploadConfig(Config $config)
    {
        $options = [];
        if ($config->has('visibility')) {
            $options['headers']['x-oss-object-acl'] = $this->normalizeVisibility($config->get('visibility'));
        }

        return $options;
    }

    /**
     * @param $visibility
     *
     * @return string
     */
    private function normalizeVisibility($visibility)
    {
        switch ($visibility) {
            case AdapterInterface::VISIBILITY_PUBLIC:
                $visibility = 'public-read';
                break;
            case AdapterInterface::VISIBILITY_PRIVATE:
                $visibility = 'private';
                break;
        }

        return $visibility;
    }

    /**
     * Get the Aliyun Oss Client bucket.
     *
     * @return string
     */
    public function getBucket()
    {
        return $this->config['bucket'];
    }

    /**
     * Get the Aliyun Oss Client instance.
     *
     * @return \OSS\OssClient
     */
    public function getClient()
    {
        return $this->client;
    }
}
