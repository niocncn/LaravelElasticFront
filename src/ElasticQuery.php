<?php

namespace Niocncn\ElasticFront;

use Niocncn\ElasticFront\Traits\SearchQueryTrait;
use Elasticsearch\ClientBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ElasticQuery
{

    use SearchQueryTrait;

    /** @var ElasticFront */
    protected $model;
    protected $client;

    protected $skipScopes = [];

    protected $must = [];
    protected $must_not = [];
    protected $should = [];
    protected $offset = 0;
    protected $limit = 10;
    protected $sort = [ 'id' => [ 'order' => 'desc' ]];
    protected $fields = [];
    protected $highlightFields = [];

    /**
     *
     */
    private function applyScopes()
    {
        foreach(get_class_methods($this->model) as $scope){
            if(str_starts_with($scope, 'globalScope')){
                $name = strtolower(str_replace('globalScope','',$scope));
                if(! in_array($name,$this->skipScopes)){
                    $this->model->{$scope}($this);
                }
            }
        }
    }



    /**
     * @param array $fields
     * @return $this
     */
    public function highlightFields(array $fields = []) : ElasticQuery
    {
        if(! Arr::isAssoc($fields)){
            $arr = [];
            foreach ($fields as $field){
                $arr[$field] = (object) [];
            }
            $fields = $arr;
        }

        $this->highlightFields = $fields;

        return $this;
    }

    /**
     * @param $name
     * @return $this
     */
    public function withoutScope($name)
    {
        $this->skipScopes[] = $name;
        return $this;
    }

    /**
     * @return array
     */
    public function getSearchBody() : array
    {
        $this->applyScopes();
        $arr = [
            'size' => $this->limit,
            'from' => $this->offset,
            "query" => [
                "bool" => [
                    "must" => $this->must,
                    "must_not" => $this->must_not,
                    "should" => $this->should
                ]
            ]
        ];

        if(! empty($this->highlightFields)){
            $arr['highlight'] = [
                'fields' => $this->highlightFields
            ];
        }

        if(count($this->fields) > 0) $arr["_source"] = $this->fields;

        if($this->sort) $arr['sort'] = $this->sort;

        return $arr;
    }

    public function __construct(ElasticFront $model, $pemPath = null)
    {
        $this->model = $model;
        $hosts = config('app.elastic_front_hosts',[]);
        if(method_exists($model,'elasticHosts') && !empty($model->elasticHosts())){
            $hosts = $model->elasticHosts();
        }

        $client = ClientBuilder::create()
            ->setHosts($hosts);

        if(! empty($pemPath)) {
            $client = $client->setSSLVerification($pemPath);
        } elseif ($path = $model->getSSLPemPath()) {
            $client = $client->setSSLVerification($path);
        } elseif($path = config('app.elastic_pem_project_relative_path')) {
            $client = $client->setSSLVerification(base_path($path));
        }

        $this->client = $client->build();
    }

    /**
     * @param array $fields
     * @return $this
     */
    public function select(array $fields) : self
    {
        $this->fields = $fields;
        return $this;
    }

    /**
     * @param $must
     * @return $this
     */
    public function must($must) : self
    {
        $this->must[] = $must;
        return $this;
    }

    /**
     * @param $must_not
     * @return $this
     */
    public function must_not($must_not) : self
    {
        $this->must_not[] = $must_not;
        return $this;
    }

    /**
     * @param $should
     * @return $this
     */
    public function should($should) : self
    {
        $this->should[] = $should;
        return $this;
    }

    /**
     * @param $offset
     * @return $this
     */
    public function offset($offset) : self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * @param $limit
     * @return $this
     */
    public function limit($limit) : self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * @param $limit
     * @return $this
     */
    public function take($limit) : self
    {
        return $this->limit($limit);
    }

    /**
     * @param $sort
     * @return $this
     */
    protected function sort($sort)
    {
        $this->sort = $sort;
        return $this;
    }

    /**
     * @param string $field
     * @param string $type
     * @return $this
     */
    public function orderBy(string $field, string $type = 'desc') : self
    {
        $this->sort([ $field => [ 'order' => $type ]]);

        return $this;
    }

    /**
     * @param array $response
     * @return Collection
     */
    private function collectHits (array $response) : Collection
    {
        return new Collection(
            array_map(
                function($item) {
                    $source = $item['_source'];
                    $source['__meta'] = [
                        'score' => Arr::get($item,'_score',0),
                        'highlight' => Arr::get($item,'highlight')
                    ];
                    return $this->loadRelations($source); },
                Arr::get($response,'hits.hits',[])
            )
        );
    }

    /**
     * Get collection of documents
     * @return Collection
     */
    public function get(array $fields = []) : Collection
    {
        $this->applyScopes();

        if(count($fields) > 0) $this->fields = $fields;

        $res = $this->client->search([
            'index' => $this->model::$elasticIndex,
            'body' => $this->getSearchBody()
        ]);

        return $this->collectHits($res);
    }

    /**
     * Get count documents
     * @return integer
     */
    public function count() : int
    {
        $this->applyScopes();

        return (int) Arr::get($this->client->count([
            'index' => $this->model::$elasticIndex,
            'body' => [
                "query" => [
                    "bool" => [
                        "must" => $this->must,
                        "must_not" => $this->must_not,
                        "should" => $this->should
                    ]
                ],
            ]
        ]),'count',0);
    }

    /**
     * Get all elements from the collection
     *
     * @return Collection
     */
    public function all() : Collection
    {
        return $this->limit(10000)->get();
    }

    /**
     * @param null $page
     * @param null $limit
     * @return ElasticPagination
     */
    public function paginate( $page = null, $limit = null ) : ElasticPagination
    {
        $this->applyScopes();

        if(! $page){
            if(request()->query('page')) $page = (int) request()->query('page');
            else $page = 1;
        }

        if($limit) $this->limit((int) $limit);
        $page = (int) $page;

        $this->offset(( $page  - 1 ) * $this->limit);

        $res = $this->client->search([
            'index' => $this->model::$elasticIndex,
            'body' => $this->getSearchBody()
        ]);

        $total = Arr::get($res,'hits.total.value',0);

        $items = $this->collectHits($res);

        return (new ElasticPagination($items,$total,$this->limit,$page,['path' => request()->path()]))->withQueryString();
    }

    /**
     * @param $id
     * @return ElasticFront
     */
    public function find($id)
    {
        $source = $this->client->getSource([
            'index' => $this->model::$elasticIndex,
            'id'    => $id,
            'client' => [ 'ignore' => 404 ]
        ]);

        if(Arr::get($source,'error')) return null;

        return $this->loadRelations($source);
    }

    /**
     * @param $id
     * @return ElasticFront
     */
    public function findOrFail($id)
    {
        $doc = $this->find($id);

        if(! $doc) throw new NotFoundHttpException();

        return $doc;
    }

    /**
     * @param array $model
     * @return ElasticFront
     */
    public function loadRelations(array $model) : ElasticFront
    {
        foreach ($model as $key => $value){
            if(Str::startsWith($key,"_") && method_exists($this->model,$key)) {
                $model[substr($key,1)] = $this->model->{$key}()->relatedModel($value);
                unset($model[$key]);
            }
        }

        return (new $this->model())->setRawAttributes($model);
    }

    /**
     * @return mixed
     */
    public function first()
    {
        return $this->get()->first();
    }

    /**
     * @return mixed
     */
    public function firstOrFail()
    {
        $elem = $this->first();

        if(! $elem) abort(404);

        return $elem;
    }

    /**
     *
     * @param $field
     * @param $value
     * @return $this
     */
    public function where($field, $value, $preserveKey = false) : self
    {
        return $this->whereIn($field, Arr::wrap($value), $preserveKey);
    }

    /**
     *
     * @param $field
     * @param $value
     * @return $this
     */
    public function whereIn(string $field, array $values, bool $preserveKey = false) : self
    {
        if(! $preserveKey) $field = $this->getTermKey($field, $values);

        $this->must([
            'terms' => [
                $field => $values
            ]
        ]);

        return $this;
    }

    /**
     *
     * @param $field
     * @param $value
     * @return $this
     */
    public function whereNotIn(string $field, array $values, bool $preserveKey = false) : self
    {
        if(! $preserveKey) $field = $this->getTermKey($field, $values);

        $this->must_not([
            'terms' => [
                $field => $values
            ]
        ]);

        return $this;
    }

    /**
     * @param string $field
     * @param array $values
     * @return $this
     */
    public function whereBetween(string $field, array $values) : self
    {
        $this->must([
            'range' => [
                "$field" => [
                    'gte' => $values[0],
                    'lte' => $values[1],
                ]
            ]
        ]);

        return $this;
    }

    /**
     * @param string $field
     * @param string $value
     * @return $this
     */
    public function whereDate(string $field, string $value) : self
    {
        return $this->where($field, $value, true);
    }

    /**
     *
     * @param $field
     * @param $value
     * @return $this
     */
    public function whereNot(string $field, $value, $preserveKey = false) : self
    {
        return $this->whereNotIn($field, Arr::wrap($value), $preserveKey);
    }

    /**
     * @param string $field
     * @param array $values
     * @return string
     */
    private function getTermKey(string $field, array $values) : string
    {
        $key = "$field.keyword";

        foreach($values as $value) {
            if(!is_string($value)) {
                $key = $field;
                break;
            }
        }

        return $key;
    }
}
