<?php

namespace Drupal\elasticentityquery;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryBase;
use Drupal\Core\Entity\Query\QueryException;
use Drupal\Core\Entity\Query\QueryInterface;

/**
 * The SQL storage entity query class.
 */
class Query extends QueryBase implements QueryInterface {

  /**
   * The elasticsearch query
   *
   * @var array
   */
  protected $query;

  /**
   * An array of fields keyed by the field alias.
   *
   * Each entry correlates to the arguments of
   * \Drupal\Core\Database\Query\SelectInterface::addField(), so the first one
   * is the table alias, the second one the field and the last one optional the
   * field alias.
   *
   * @var array
   */
  protected $sqlFields = array();

  /**
   * An array of strings added as to the group by, keyed by the string to avoid
   * duplicates.
   *
   * @var array
   */
  protected $sqlGroupBy = array();

  /**
   * @var \Elasticsearch\Client
   */
  protected $client;

  /**
   * Constructs a query object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param string $conjunction
   *   - AND: all of the conditions on the query need to match.
   *   - OR: at least one of the conditions on the query need to match.
   * @param \Elasticsearch\Client $client
   *   Elasticsearch client to use
   * @param array $namespaces
   *   List of potential namespaces of the classes belonging to this query.
   */
  public function __construct(EntityTypeInterface $entity_type, $conjunction = 'AND', \Elasticsearch\Client $client, array $namespaces) {
    parent::__construct($entity_type, $conjunction, $namespaces);
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $result = $this->getResult();

    if ($this->count) {
      return $result['count'];
    }
    else {
      // TODO: support for revisions? See https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Entity%21Query%21QueryInterface.php/function/QueryInterface%3A%3Aexecute/8.2.x
      $ids = array();
      foreach ($result['hits']['hits'] as $hit) {
        $ids[$hit['_id']] = $hit['_id'];
      }
      return $ids;
    }

  }

  public function getResult() {
    $params = $this->buildRequest();
    if ($this->count) {
      $result = $this->client->count($params);
    }
    else {
      $result = $this->client->search($params);
    }
    
    return $result;
  }

  public function debug() {
    return $this->buildRequest();
  }

  private function buildRequest() {
    $params = [
      'index' => $this->entityTypeId,
    ];

    // For regular (non-count) queries    
    if (!$this->count) {
       // Don't include source
       $params['body']['_source'] = false;
    }

    // Top-level condition
    $params['body']['query']['bool'] = $this->getElasticFilterItem($this->condition);

    // Sorting
    if (!empty($this->sort)) {
      foreach ($this->sort as $sort) {
        $params['body']['sort'] = [$sort['field'] => ['order' => strtolower($sort['direction'])]];
      }
    }

    // Range
    if (!empty($this->range) && !$this->count) {
      if (!empty($this->range['start'])) {
        $params['body']['from'] = $this->range['start'];
      }
      if (!empty($this->range['length'])) {
        $params['body']['size'] = $this->range['length'];
      }
    }

    // Allow near unlimited results by default (10,000)
    // TODO: Make this configurable
    if (!$this->count) {
      $params['body']['size'] = $params['body']['size'] ?? (10000 - ($params['body']['from'] ?? 0));
    }

    return $params;
  }

  private function getElasticFilterItem(\Drupal\Core\Entity\Query\Sql\Condition $condition) {
    $conjunction = $condition->getConjunction();
    $bool = ['must' => []];
    foreach ($condition->conditions() as $subcondition) {
      $operator = $subcondition['operator'] ?? '=';
      $field = $subcondition['field'];
      $value = $subcondition['value'];

      if (is_object($field) && $conjunction == "AND") {
        $bool['must'][] = ['bool' => $this->getElasticFilterItem($field)];
      }
      elseif (is_object($field) && $conjunction == "AND") {
        $bool['should'][] = ['bool' => $this->getElasticFilterItem($field)];
      }
      elseif ($operator == "=" && $conjunction == "AND") {
        $bool['must'][] = ['term' => [$field => $value]];
      }
      elseif ($operator == "=" && $conjunction == "OR") {
        $bool['should'][] = ['term' => [$field => $value]];
      }
      elseif (($operator == "<>" || $operator == "!=") && $conjunction == "AND") {
        $bool['must_not'][] = ['term' => [$field => $value]];
      }
      elseif (($operator == "<>" || $operator == "!=") && $conjunction == "OR") {
        $bool['should'][] = ['bool' => ['must_not' => ['term' => [$field => $value]]]];
      }
      elseif ($operator == "IN" && $conjunction == "AND") {
        $bool['must'][] = ['terms' => [$field => $value]];
      }
      elseif ($operator == "IN" && $conjunction == "OR") {
        $bool['should'][] = ['terms' => [$field => $value]];
      }
      elseif ($operator == "NOT IN" && $conjunction == "AND") {
        $bool['must_not'][] = ['terms' => [$field => $value]];
      }
      elseif ($operator == "NOT IN" && $conjunction == "OR") {
        $bool['should'][] = ['bool' => ['must_not' => ['terms' => [$field => $value]]]];
      }
      elseif ($operator == "IS NULL" && $conjunction == "AND") {
        $bool['must_not'][] = ['exists' => ['field' => $field]];
      }
      elseif ($operator == "IS NULL" && $conjunction == "OR") {
        $bool['should'][] = ['bool' => ['must_not' => ['exists' => ['field' => $field]]]];
      }
      elseif ($operator == "IS NOT NULL" && $conjunction == "AND") {
        $bool['must'][] = ['exists' => ['field' => $field]];
      }
      elseif ($operator == "IS NOT NULL" && $conjunction == "OR") {
        $bool['should'][] = ['exists' => ['field' => $field]];
      }
      elseif ($operator == ">" && $conjunction == "AND") {
        $bool['must'][] = ['range' => [$field => ['gt' => $value]]];
      }
      elseif ($operator == ">" && $conjunction == "OR") {
        $bool['should'][] = ['range' => [$field => ['gt' => $value]]];
      }
      elseif ($operator == ">=" && $conjunction == "AND") {
        $bool['must'][] = ['range' => [$field => ['gte' => $value]]];
      }
      elseif ($operator == ">=" && $conjunction == "OR") {
        $bool['should'][] = ['range' => [$field => ['gte' => $value]]];
      }
      elseif ($operator == "<" && $conjunction == "AND") {
        $bool['must'][] = ['range' => [$field => ['lt' => $value]]];
      }
      elseif ($operator == "<" && $conjunction == "OR") {
        $bool['should'][] = ['range' => [$field => ['lt' => $value]]];
      }
      elseif ($operator == "<=" && $conjunction == "AND") {
        $bool['must'][] = ['range' => [$field => ['lte' => $value]]];
      }
      elseif ($operator == "<=" && $conjunction == "OR") {
        $bool['should'][] = ['range' => [$field => ['lte' => $value]]];
      }
      elseif ($operator == "BETWEEN") {
        natsort($value);
        if ($conjunction == "AND") {
          $bool['must'][] = ['range' => [$field => [
            'gt' => $value[0],
            'lt' => $value[1],
          ]]];
        }
        elseif ($conjunction == "OR") {
          $bool['should'][] = ['range' => [$field => [
            'gt' => $value[0],
            'lt' => $value[1],
          ]]];
        }
      }
      elseif ($operator == "STARTS_WITH" && $conjunction == "AND") {
        $bool['must'][] = ['prefix' => [$field => $value]];
      }
      elseif ($operator == "STARTS_WITH" && $conjunction == "OR") {
        $bool['should'][] = ['prefix' => [$field => $value]];
      }
      elseif ($operator == "ENDS_WITH" && $conjunction == "AND") {
        //TODO: WARNING THIS IS EXPENSIVE
        $bool['must'][] = ['wildcard' => [$field => '*'.$value]];
      }
      elseif ($operator == "ENDS_WITH" && $conjunction == "OR") {
        //TODO: WARNING THIS IS EXPENSIVE
        $bool['should'][] = ['wildcard' => [$field => '*'.$value]];
      }
      elseif ($operator == "CONTAINS" && $conjunction == "AND") {
        //TODO: WARNING THIS IS EXPENSIVE
        $bool['must'][] = ['wildcard' => [$field => '*'.$value.'*']];
      }
      elseif ($operator == "CONTAINS" && $conjunction == "OR") {
        //TODO: WARNING THIS IS EXPENSIVE
        $bool['should'][] = ['wildcard' => [$field => '*'.$value.'*']];
      }
    }

    return $bool;
  }


}