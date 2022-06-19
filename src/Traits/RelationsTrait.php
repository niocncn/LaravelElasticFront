<?php

namespace Niocncn\ElasticFront\Traits;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

trait RelationsTrait
{

    protected $preloadCacheTimeMinutes = 2;

    public function relatedModel( $value)
    {
        if(is_array($value)){
            $collection = collect([]);
            foreach ($value as $item){
                $found = $this->findRelated($item);
                if($found) $collection->add($found);
            }
            return $collection;
        }
        if(is_string($value) || is_int($value)){
            return $this->findRelated($value);
        }
    }

    protected function findRelated($id)
    {
        if($this->preloadFull){
            $preloaded = $this->preloadFull();
            return Arr::get($preloaded,"$id");
        }

        // TODO подумать и переделать потом
        return Cache::remember(md5(get_called_class() . __METHOD__ . $id),Carbon::now()->addMinutes($this->preloadCacheTimeMinutes),function() use ($id) {
            return $this->newQuery()->find($id);
        });
    }

    protected function preloadFull()
    {
        return Cache::remember(md5(get_called_class() . __METHOD__ ),Carbon::now()->addMinutes($this->preloadCacheTimeMinutes),function(){
            return $this->newQuery()->limit(1000)->get()->keyBy("id");
        });
    }

}
