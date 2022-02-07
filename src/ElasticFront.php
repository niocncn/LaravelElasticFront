<?php

namespace Niocncn\ElasticFront;

use Niocncn\ElasticFront\Traits\ModelQuery;
use Niocncn\ElasticFront\Traits\RelationsTrait;
use Illuminate\Contracts\Support\Arrayable;
use ArrayAccess;

abstract class ElasticFront implements Arrayable, ArrayAccess {

    use RelationsTrait, ModelQuery;

    public static $elasticIndex;
    public static $wpEntity;
    public static $searchFields = [];
    protected $client;
    protected $scopes = [];

    /**
     * @param array $model
     * @return array
     */
    public static function prepareFrontElastic(array $model) : array
    {
        return [];
    }

    public function getSSLPemPath()
    {
        return null;
    }

    /**
     * @return array
     */
    public static function elasticIndexSettings() : array
    {
        return [
            'settings' => [
                'number_of_shards' => 1,
                'number_of_replicas' => 0
            ]
        ];
    }

    /** @var bool При рилейшенах загружать полностью весь список */
    protected $preloadFull = true;

    /**
     * @return array
     */
    public function elasticHosts() : array
    {
        return [];
    }

    /**
     * @return ElasticQuery
     */
    public static function query() : ElasticQuery
    {
        return (new static)->newQuery();
    }

    /**
     * @return ElasticQuery
     */
    public function newQuery() : ElasticQuery
    {
        return new ElasticQuery($this);
    }

    /**
     * @param $className
     * @return ElasticFront
     */
    public function relatedTo($className) : ElasticFront
    {
        return new $className();
    }

    /**
     * @return array
     */
    public function toArray() : array
    {
        return $this->attributes;
    }

    /**
     * @return string
     */
    public function toJson() : string
    {
        return json_encode($this->toArray());
    }

}
