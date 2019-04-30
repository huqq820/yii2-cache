<?php
namespace common\components;

use yii\base\Component;
use common\models\RedisConnecter;

/**
 * 用于生成和组装命名空间，包括项目层命名空间、业务层命名空间
 * 所有的缓存key，均由命名空间与业务、参数组合而成
 * 本类不处理某个业务的缓存，只负责命名空间的生成和组装业务缓存key，具体的业务缓存操作由上层控制器实现。
 * @author oneday
 *
 */
abstract class AbstractCacheService extends Component
{
    // 命名空间默认缓存时长，单位秒
    public $cacheTime = 0;
    // 命名空间cache redis实例名
    public $cacheInstance = "";
    // 缓存key的前缀
    public $cachePrefix = "";
    // 命名空间项目名称
    public $projectName = '';
    
    /**
     * 获取指定cachekey的缓存内容
     * @param string $cacheKey      缓存key
     * @param string $isMaster      默认从库读取
     * @return string
     */
    protected abstract function getCacheContent($cacheKey, $isMaster = false);
    
    /**
     * 设置指定cachekey的缓存内容
     * @param string $cacheKey
     * @param int $cacheTime
     * @param string $content
     * @return boolean
     */
    protected abstract function setCacheContent($cacheKey, $cacheTime, $content);
    
    /**
     * 批量删除指定key的缓存
     * @param array $cacheKeys
     * @return boolean
     */
    protected abstract function deleteCacheContent($cacheKeys);
    
    
    /**
     * 获取指定信息缓存key
     * @param string $businessName      业务名称，如user.info、user.list
     * @param string $businessParam     缓存key关键参数，如：email:xxx:1:20
     * @param string $projectName       项目名称，如passport
     * @param string 业务缓存Key
     */
    public function getCacheKey($businessName, $businessParam = '', $projectName = '')
    {
        if (empty($businessName)) {
            return false;
        }
        if (empty($projectName)) {
            $projectName = $this->projectName;
        }
        
        $nsValue = $this->getNameSpace($projectName, $businessName);
        if (empty($nsValue)) {
            return false;
        }
        // 获取项目和业务的命名空间值
        $pnsValue = $nsValue['project'];
        $bnsValue = $nsValue['business'];
        
        $cacheKey = $this->cachePrefix . ":{$pnsValue}:{$bnsValue}:{$businessName}";
        if (!empty($businessParam)) {
            $code = $this->getSecretCode($businessParam);
            $cacheKey .= ":" . $code;
        }
        return $cacheKey;
    }
    
    
    /**
     * 刷新指定项目的命名空间，即该项目下的所有cache失效，重新生成
     * @param string $projectName   项目名称
     * @return boolean
     */
    public function refreshProjectNameSpace($projectName = '')
    {
        if (empty($projectName)) {
            $projectName = $this->projectName;
        }
        $pnsValue = $this->createNameSpace('project', $projectName);
        return $pnsValue;
    }
    
    
    /**
     * 刷新指定业务的命名空间，即该业务下的所有cache失效，重新生成
     * @param string $businessName   业务名称，如user.info、user.list
     * @return boolean
     */
    public function refreshBusinessNameSpace($businessName, $projectName = '')
    {
        if (empty($businessName)) {
            return false;
        }
        if (empty($projectName)) {
            $projectName = $this->projectName;
        }
        
        // 业务名称：指定项目下的业务名称，避免多个项目的业务名重复
        $businessName = $projectName . '.' . $businessName;
        $bnsValue = $this->createNameSpace('business', $businessName);
        return $bnsValue;
    }
    
    
    /**
     * 获取指定资源类别的命名空间值
     * @param string $projectName     项目名，qzone
     * @param string $businessName    业务名，如user.info、user.list
     *  完整的缓存命名空间key = value 
     *  如：
     *  项目命名空间 qz:pns = "时间戳+随机数"
     *      qz:pns:rsid:加密的qzone = "时间戳+随机数"
     *  模块命名空间 qz:bns:rsid:加密的业务名 = "时间戳+随机数"
     *      qz:bns:rsid:加密的user = "时间戳+随机数"
     *      qz:bns:rsid:加密的feed = "时间戳+随机数"
     *  业务缓存key (qz:项目命名空间值:业务命名空间值:业务名:加密业务参数)
     *      qz:pnsvalue:bnsvalue:user.info:加密的email:xxx:1:20
     * @return string   值为时间戳+随机数
     */
    private function getNameSpace($projectName, $businessName)
    {
        if (empty($projectName) || empty($businessName)) {
            return false;
        }
        
        // 组合项目命名空间
        $pCacheKey = $this->cachePrefix . ":pns";
        $pCode = $this->getSecretCode($projectName);
        $pCacheKey .= ":rsid:" . $pCode;
        
        // 组合业务命名空间
        $businessName = $projectName . '.' . $businessName;
        $bCacheKey = $this->cachePrefix . ":bns";
        $bCode = $this->getSecretCode($businessName);
        $bCacheKey .= ":rsid:" . $bCode;
        
        $redisObj = new RedisConnecter();
        $cacheObj = $redisObj->connRedis($this->cacheInstance, false);
        $valueArr = $cacheObj->mget(array($pCacheKey, $bCacheKey));
        if (empty($valueArr)) {
            return false;
        }
        
        // 项目或业务的命名空间不存在,则重新创建
        $pnsValue = $valueArr[0];
        $bnsValue = $valueArr[1];
        if (empty($pnsValue)) {
            $pnsValue = $this->createNameSpace('project', $projectName);
        }
        if (empty($bnsValue)) {
            $bnsValue = $this->createNameSpace('business', $businessName);
        }
        return array('project' => $pnsValue, 'business' => $bnsValue);
    }
    
    
    /**
     * 生成指定资源ID的命名空间，用户创建或更新cache
     * @param string $type          命名空间类型，project-项目，model-模块
     * @param string $resourceId
     * @param number $cache_time
     * @return boolean
     */
    private function createNameSpace($type, $resourceId = "", $cacheTime = 0)
    {
        if (empty($type) || !in_array($type, array('project', 'business'))) {
            return false;
        }
        if ($type == 'project') {
            $cacheKey = $this->cachePrefix . ":pns";
        } else if ($type == 'business') {
            $cacheKey = $this->cachePrefix . ":bns";
        }
        if (!empty($resourceId)) {
            $code = $this->getSecretCode($resourceId);
            $cacheKey .= ":rsid:" . $code;
        }
        
        if (empty($cacheTime)) {
            $cacheTime = $this->cacheTime;
        }
        $content = time() . mt_rand(1000, 9999);
        
        $redisObj = new RedisConnecter();
        $cacheObj = $redisObj->connRedis($this->cacheInstance, true);
        $res = $cacheObj->setex($cacheKey, $cacheTime, $content);
        return $content;
    }
    
    
    // 加密code
    private function getSecretCode($code)
    {
        if (!empty($code)) {
            $code = sprintf("%X", crc32($code));
        }
        return $code;
    }
    
}