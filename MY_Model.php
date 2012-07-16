<?php defined('BASEPATH') OR exit('No direct script access allowed');

class MY_Model extends CI_Model {}

final class Filtering {
	const ALL = 1;
	const EXACT = 2;
	const GT = 3;
	const LT = 4;
	const GTE = 5;
	const LTE = 6;
	const LIKE = 7;
	const KEYWORDS = 8;
}

final class FieldType {
	const NUMERIC = 1;
	const TEXT = 2;
	const DATE = 3;
	const TIME = 4;
	const DATETIME = 5;
	const BOOLEAN = 6;
}

final class RelationType {
	const ONE_TO_ONE = 1;
	const MANY_TO_ONE = 2;
	const ONE_TO_MANY = 3;
	const MANY_TO_MANY = 4;
}

final class Field {
	public $type;
	public $maxlength;
	public $nullable = true;
	public $autoincrement = false;
	public $unsigned = false;
	public $default;
	public $readonly = false;
	public $unique = false;
	public $helptext = '';

	private function Field ($params) {
		$properties = get_class_vars(__CLASS__);

		foreach ($properties as $property => $value) {
			if (array_key_exists($property, $params)) {
				$this->{$property} = $params[$property];
			}
		}
	}

	public static function Number($params = array()) {
		$params['type'] = FieldType::NUMERIC;
		return new Field($params);
	}

	public static function Text($params = array()) {
		$params['type'] = FieldType::TEXT;
		return new Field($params);
	}

	public static function Date($params = array()) {
		$params['type'] = FieldType::DATE;
		return new Field($params);
	}

	public static function Time($params = array()) {
		$params['type'] = FieldType::TIME;
		return new Field($params);
	}

	public static function Datetime($params = array()) {
		$params['type'] = FieldType::DATETIME;
		return new Field($params);
	}

	public static function Boolean($params = array()) {
		$params['type'] = FieldType::BOOLEAN;
		return new Field($params);
	}
}

final class Relation {
	public $type;
	public $model;
	public $through;
	public $name;
	public $direct;
	public $full = false;

	private function Relation($params) {
		$properties = get_class_vars(__CLASS__);

		foreach ($properties as $property => $value) {
			if (array_key_exists($property, $params)) {
				$this->{$property} = $params[$property];
			}
		}
	}

	public static function OneToOne($params) {
		$params['type'] = RelationType::ONE_TO_ONE;
		return new Relation($params);
	}

	public static function ManyToOne($params) {
		$params['type'] = RelationType::MANY_TO_ONE;
		return new Relation($params);
	}

	public static function OneToMany($params) {
		$params['type'] = RelationType::ONE_TO_MANY;
		return new Relation($params);
	}

	public static function ManyToMany($params) {
		$params['type'] = RelationType::MANY_TO_MANY;
		return new Relation($params);
	}
}

final class AppException extends Exception {
	private $arrayMessage = false;

	public function AppException($message = NULL, $code = 500) {
		if (is_array($message)) {
			$this->arrayMessage = true;
			$message = json_encode($message);
		}

		parent::__construct($message, $code);
	}

	public function getError() {
		if ($this->arrayMessage) {
			return json_decode($this->getMessage());
		}

		return $this->getMessage();
	}

}

if (!function_exists('startsWith')) {
	function startsWith($haystack, $needle, $case = true) {
		$function = $case ? "strcmp" : "strcasecmp";
	    return $function(substr($haystack, 0, strlen($needle)), $needle) === 0;
	}
}

abstract class ModelResource extends CI_Model {
	public $table;
	public $key;
	public $filtering = array();
	public $columns = array();
	public $relations = array();
	public $pagination = true;
	public $defaultPaginationLimit = 20;
	public $orderby;

	public function getCount($params = array()) {
		$this->filtering($params);
		$this->parseRelations($params);

		return $this->db->count_all_results($this->table);
	}

	public function getById($id) {
		$result = $this->get(array(
			$this->key => $id
		));

		if ($this->pagination) {
			$result = $result['objects'];
		}

		if (count($result) > 0) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	public function get($params = array()) {
		if ($this->pagination) {
			$this->pagination($params);
		}

		$this->filtering($params);
		$this->sorting($params);
		$this->parseRelations();
		$this->db->select($this->getColumnsQuery());
		$rset = $this->db->get($this->table);

		if ($rset) {
			$result = $rset->result();
			$this->normalizeRelations($result);
			$this->compoundAttributes($result);
			$this->complexRelations($result);

			if ($this->pagination) {
				$result = $this->wrapPagination($result, $params);
			}

			return $result;
		} else {
			throw new AppException("({$this->db->_error_number()}) {$this->db->_error_message()}", 500);
		}
	}

	public function resolveRelation(&$resource, $relationColumn) {
		$relation = $this->relations[$relationColumn];

		if ($relation->type === RelationType::MANY_TO_MANY) {
			$this->load->model($relation->through);
			$this->load->model($relation->model);
			$foreign = $this->{$relation->model};
			$intermediate = $this->{$relation->through};
			$intermediate->pagination = false;
			$intermediate->filtering[$this->key] = Filtering::EXACT;
			$intermediate->relations[$foreign->key] = Relation::ManyToOne(array('model' => $relation->model, 'full' => true));

			if (is_object($resource)) {
				$rset = $intermediate->get(array(
					$this->key => $resource->{$this->key}
				));

				if ($relation->direct) {
					$resource->{$relation->name} = array();

					foreach ($rset as $foreignRow) {
						$resource->{$relation->name}[] = $foreignRow->{$foreign->key};
					}
				} else {
					$resource->{$relation->name} = $rset;
				}
			} else {
				$rset = $intermediate->get(array(
					$this->key => $resource
				));

				if ($relation->direct) {
					$relationData = array();

					foreach ($rset as $foreignRow) {
						$relationData[] = $foreignRow->{$foreign->key};
					}

					return $relationData;
				} else {
					return $rset;
				}
			}
		}
	}

	private function complexRelations(&$result) {
		foreach ($this->relations as $relation) {
			if ($relation->type === RelationType::MANY_TO_MANY && $relation->full) {
				$this->load->model($relation->through);
				$this->load->model($relation->model);
				$foreign = $this->{$relation->model};
				$intermediate = $this->{$relation->through};
				$intermediate->pagination = false;
				$intermediate->filtering[$this->key] = Filtering::EXACT;
				$intermediate->relations[$foreign->key] = Relation::ManyToOne(array('model' => $relation->model, 'full' => true));

				foreach ($result as &$row) {
					$rset = $intermediate->get(array(
						$this->key => $row->{$this->key}
					));

					if ($relation->direct) {
						$row->{$relation->name} = array();

						foreach ($rset as $foreignRow) {
							$row->{$relation->name}[] = $foreignRow->{$foreign->key};
						}
					} else {
						$row->{$relation->name} = $rset;
					}
				}
			}
		}
	}

	private function pagination(&$params) {
		if (!array_key_exists('offset', $params)) {
			$params['offset'] = 0;
		}

		if (array_key_exists('limit', $params) && $params['limit'] == 0) {
			$params['offset'] = 0;
			return;
		}

		if (!array_key_exists('limit', $params)) {
			$params['limit'] = $this->defaultPaginationLimit;
		}

		$this->db->limit($params['limit'], $params['offset']);
	}

	private function wrapPagination($result, $params) {
		$totalObjects = $this->getCount($params);
		$wrapper = array(
			'meta' => array(
				'limit' => $params['limit'],
				'offset' => $params['offset'],
				'total' => $totalObjects,
				'nextPage' => null,
				'prevPage' => null
			),
			'objects' => $result
		);

		if ($params['limit'] > 0) {
			$currentOffset = $params['offset'];
			$nextOffset = $params['offset'] + $params['limit'];
			$prevOffset = $params['offset'] - $params['limit'];

			if ($nextOffset < $totalObjects) {
				$params['offset'] = $nextOffset;
				$wrapper['meta']['nextPage'] = current_url() . '/?' . http_build_query($params);
			}

			if ($currentOffset > 0) {
				$params['offset'] = max(0, $prevOffset);
				$wrapper['meta']['prevPage'] = current_url() . '/?' . http_build_query($params);
			}
		}

		return $wrapper;
	}

	private function filtering($params) {
		foreach ($params as $filter => $value) {
			if ($filter === 'format') {
				continue;
			}

			$column = NULL;
			$filterType = $this->parseFilterAndExtractColumnName($filter, $column);
			$columnExists = $this->columnExists($column);

			if ($columnExists) {
				$allowsFiltering = $this->allowsFiltering($column, $filterType);

				if (! $allowsFiltering) {
					throw new AppException("Column '{$column}' doesn't accept filtering", 409);
				}

				switch ($filterType) {
					case Filtering::EXACT: $this->db->where("{$this->table}.{$column}", $value); break;
					case Filtering::GT: $this->db->where("{$this->table}.{$column} >", $value); break;
					case Filtering::GTE: $this->db->where("{$this->table}.{$column} >=", $value); break;
					case Filtering::LT: $this->db->where("{$this->table}.{$column} <", $value); break;
					case Filtering::LTE: $this->db->where("{$this->table}.{$column} <=", $value); break;
					case Filtering::LIKE: $this->db->where("{$this->table}.{$column} LIKE", "%{$value}%"); break;
					case Filtering::KEYWORDS:
						foreach ($value as $keyword) {
							$this->db->where("{$this->table}.{$column} LIKE", "%{$keyword}%");
						}
						break;
				}				
			}
		}
	}

	private function parseFilterAndExtractColumnName($filter, &$column) {
		$filterRegexMatches = array();

		if (preg_match('/(?P<filter>__(gt|gte|lt|lte|like|keyword))$/', $filter, $filterRegexMatches)) {
			$filterStringLength = strlen($filterRegexMatches['filter']);
			$column = substr($filter, 0, -$filterStringLength);

			switch ($filterRegexMatches['filter']) {
				case '__gt': return Filtering::GT;
				case '__gte': return Filtering::GTE;
				case '__lt': return Filtering::LT;
				case '__lte': return Filtering::LTE;
				case '__like': return Filtering::LIKE;
				case '__keyword': return Filtering::KEYWORDS;
			}
		} else {
			$column = $filter;
			return Filtering::EXACT;
		}
	}

	private function columnExists($column) {
		return array_key_exists($column, $this->columns);	
	}

	private function allowsFiltering($column, $filterType) {
		$filterDefined = array_key_exists($column, $this->filtering);

		if (! $filterDefined && $column === $this->key) {
			$this->filtering[$column] = array(Filtering::EXACT);
			$filterDefined = true;
		}

		if ($filterDefined && is_array($this->filtering[$column])) {
			$acceptsFilterType = $filterDefined && (in_array(Filtering::ALL, $this->filtering[$column]) || in_array($filterType, $this->filtering[$column]));
		} else {
			$acceptsFilterType = $filterDefined && ($this->filtering[$column] === Filtering::ALL || $this->filtering[$column] === $filterType);
		}

		return $acceptsFilterType;
	}

	private function sorting($params) {
		$orderColumnSpecified = array_key_exists('__sortby', $params);

		if ($orderColumnSpecified) {
			$orderColumn = $params['__sortby'];

			if (strlen($orderColumn) > 0) {
				$orderDirection = $orderColumn[0] === '-' ? 'DESC' : 'ASC';

				if ($orderDirection === 'DESC') {
					$orderColumn = substr($orderColumn, 1);
				}

				if (! $this->columnExists($orderColumn)) {
					throw new AppException("Column '{$orderColumn}' doesn't exists", 409);
				}

				$this->db->order_by("{$this->table}.{$orderColumn}", $orderDirection);
			}
		} else if (!is_null($this->orderby)) {
			$this->db->order_by($this->orderby);
		}
	}

	private function parseRelations() {
		foreach ($this->relations as $column => $relation) {
			if ($relation->full) {
				switch ($relation->type) {
					case RelationType::MANY_TO_ONE:
						$this->load->model($relation->model);
						$foreign = $this->{$relation->model};
						$this->db->join($foreign->table, "{$foreign->table}.{$foreign->key} = {$this->table}.{$column}");
						break;

					case RelationType::MANY_TO_MANY:
						break;
				}
			}
		}
	}

	private function getColumnsQuery($options = array()) {
		$table = $this->table;
		$prefix = array_key_exists('prefix', $options) ? "{$options['prefix']}__" : '';
		$columns = array_keys($this->columns);
		$columnsQuery = array();

		foreach ($columns as $column) {
			$hasFullRelation = array_key_exists($column, $this->relations) && $this->relations[$column]->full;

			if ($hasFullRelation) {
				$relation = $this->relations[$column];

				switch ($relation->type) {
					case RelationType::MANY_TO_ONE:
						$this->load->model($relation->model);
						$foreign = $this->{$relation->model};
						$columnsQuery[] = $foreign->getColumnsQuery(array('prefix' => $column));
						break;

					case RelationType::MANY_TO_MANY:
						$columnsQuery[] = "{$table}.{$column} AS {$prefix}{$column}";
						break;
				}
			} else {
				$columnsQuery[] = "{$table}.{$column} AS {$prefix}{$column}";
			}
		}

		return implode(', ', $columnsQuery);
	}

	private function normalizeRelations($result) {
		foreach ($this->relations as $relationColumn => $relation) {
			if ($relation->full) {

				switch ($relation->type) {
					case RelationType::MANY_TO_ONE:
						foreach ($result as &$row) {
							$relationObject = new stdClass();
							$relationColumnStringLength = strlen($relationColumn) + 2;

							foreach ($row as $column => $value) {
								if (startsWith($column, $relationColumn)) {
									$relationObject->{substr($column, $relationColumnStringLength)} = $value;
									unset($row->{$column});
								}
							}

							$row->{$relationColumn} = $relationObject;
						}
						break;
				}
			}
		}
	}

	private function compoundAttributes($result) {
		$classFunctions = get_class_methods($this);

		foreach ($classFunctions as $function) {
			if (startsWith($function, 'attr__')) {
				$propertyName = substr($function, 6);

				foreach ($result as &$row) {
					$row->{$propertyName} = $this->{$function}($row);
				}
			}
		}
	}

	public function delete($id) {
		$rset = $this->db->where($this->key, $id)->delete($this->table);
		return $rset && $this->db->affected_rows();
	}

	public function create($data) {
		return $this->save($data);
	}

	public function update($id, $data) {
		return $this->save($data, $id);
	}

	private function save($data, $id = NULL) {
		if (is_null($id)) {
			$this->verifyNullableFields($data);
		}

		$this->removeNonColumnsKeys($data);
		$this->dehydrateRelations($data);
		$this->verifyUniqueFields($data);

		if (count($data) > 0) {
			if (is_null($id)) {
				$rset = $this->db->insert($this->table, $data);
			} else {
				$rset = $this->db->where($this->key, $id)->update($this->table, $data);
			}

			if (!$rset) {
				throw new AppException("({$this->db->_error_number()}) {$this->db->_error_message()}", 500);
			}
		}

		return is_null($id) ? $this->db->insert_id() : $this->db->affected_rows();
	}

	private function verifyNullableFields($data) {
		$errors = array();

		foreach ($this->columns as $columnName => $column) {
			$columnNullable = $column->nullable;
			$columnReceived = array_key_exists($columnName, $data) && !is_null($data[$columnName]);

			if (!$columnNullable && !$columnReceived) {
				$errors[$columnName] = "Can't be null";
			}
		}

		if (count($errors) > 0) {
			throw new AppException($errors, 409);
		}
	}

	private function removeNonColumnsKeys(&$data) {
		foreach ($data as $column => $value) {
			$columnExists = $this->columnExists($column);

			if ($columnExists) {
				$isWritable = $this->isWritable($column);

				if (!$isWritable) {
					unset($data[$column]);
				}
			} else {
				unset($data[$column]);
			}
		}
	}

	private function isWritable($column) {
		return !$this->columns[$column]->readonly;
	}

	private function dehydrateRelations(&$data) {
		foreach ($data as $column => $value) {
			if (is_array($value)) {
				$isRelated = array_key_exists($column, $this->relations);

				if ($isRelated) {
					$relation = $this->relations[$column];
					$this->load->model($relation->model);
					$foreign = $this->{$relation->model};
					$foreignIdDefined = array_key_exists($foreign->key, $value);

					if ($foreignIdDefined) {
						$data[$column] = $value[$foreign->key];
					} else {
						unset($data[$column]);
					}
				}
			}
		}
	}

	private function verifyUniqueFields($data) {
		$errors = array();

		foreach ($data as $column => $value) {
			$isUnique = $this->columns[$column]->unique;

			if ($isUnique) {
				$uniqueInUse = $this->uniqueInUse($column, $value);

				if ($uniqueInUse) {
					$error[$column] =  "Value already taken";
				}
			}
		}

		if ($errors) {
			throw new AppException($errors, 409);
		}
	}

	private function uniqueInUse($column, $value, $actualId = NULL) {
		$this->db->where($column, $value);

		if (!is_null($actualId)) {
			$this->db->where("{$this->key} <>", $actualId);
		}

		$count = $this->db->count_all_results($this->table);
		return $count > 0;
	}

}