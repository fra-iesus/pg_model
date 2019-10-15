<?php
class PgListing {
	var $definition = [
		'query'    => '',
		'class'    => '',
		'pg_class' => '',
		'columns'  => [],
		'filters'  => [], # key => value
		'ordering' => [], # key => how
		'counts'   => [], # table => row
		'autoload' => true,
	];
	var $list    = [];
	var $offset  = null;
	var $limit   = null;
	var $current = array(
		'index' => null,
		'keys'  => null,
	);
	var $query   = null;

	protected $_loaded = false;
	protected $_count  = null; 
	protected $c       = null;

	function __construct(&$config, $params = []) {
		$class = get_called_class();
		$class = str_replace('\\Listing', '', $class);
		$this->definition['pg_class'] = $class;
		$class = strtolower(str_replace('\\', '.', $class));
		$this->set_class($class);

		if (!$config) {
			throw new Exception("Config for class {$this->get_class()} not defined!");
		}
		if (!$config['db']) {
			throw new Exception("Database connection for class {$this->get_class()} not defined!");
		}
		$this->c = &$config;


		if ($params['offset']) {
			$this->offset = $params['offset'];
		}
		if ($params['limit']) {
			$this->limit  = $params['limit'];
		}
		if ($params['current']) {
			$this->current['keys'] = $params['current'];
		}
		if ($params['filters']) {
			$this->filter($params['filters']);
		}
		if ($params['ordering']) {
			$this->order_by($params['ordering']);
		}
		if ($params['counts']) {
			$this->set_counts($params['counts']);
		}
		if ( ($this->definition['query'] || $this->definition['class']) && ($this->definition['autoload']) ) {
			$this->load();
		}

		return $this;
	}

	public function __set($name, $value) {
		if (!$name) {
			return;
		}
		$this->definition['filters'][$name] = $value;
		if (!array_key_exists($name, $this->definition['columns'])) {
			$this->definition['columns'][$name] = [
				'type' => 'varchar',
			];
		}
	}

	function is_loaded() {
		return $this->_loaded;
	}

	function count() {
		return $this->_count;
	}

	function filter($filters = null) {
		if (is_array($filters)) {
			$this->definition['filters'] = $filters;
		} else {
			$this->definition['filters'] = [];
		}
		return $this;
	}

	function columns($columns = null) {
		if (is_array($columns)) {
			$this->definition['columns'] = $columns;
		} else {
			$this->definition['columns'] = [];
		}
		return $this;
	}

	function order_by($ordering = null) {
		if (is_array($ordering)) {
			$this->definition['ordering'] = $ordering;
		} else {
			$this->definition['ordering'] = [];
		}
		return $this;
	}

	function set_counts($counts = null) {
		if (is_array($counts)) {
			$this->definition['counts'] = $counts;
		} else {
			$this->definition['counts'] = [];
		}
		return $this;
	}

	function load() {
		if ($res = pg_query($this->c['db'],$this->prepare_query())) {
			$this->_count = 0;
			$class;
			$this->list = [];
			if (isset($this->definition['pg_class']) && ($this->definition['pg_class'] != '')) {
				$class = '\\' . $this->definition['pg_class'];
			}
			while ($values = pg_fetch_assoc($res)) {
				if (is_array($this->current['keys']) && !sizeof(array_diff($values, $this->current['keys']))) {
					$this->current['index'] = sizeof($this->list);
				}
				$value = new $class($this->c);
				foreach ($this->definition['counts'] as $key => $val) {
					$column = str_replace('.', '_', $key);
					$value->add_property($column);
				}
				$value->parse_params($values);
				$value->promise_is_loaded();
				array_push($this->list, $value);
				$this->_count++;
			}
			return ($this->_loaded = true);
		}
		return ($this->_loaded = false);
	}

	function listing() {
		return $this->list;
	}

	function get_class() {
		return $this->definition['class'];
	}

	function set_class($class) {
		$this->definition['class'] = $class;
		return $this;
	}

	function to_hash() {
		$hash = array();
		foreach ($this->list as $key => $value) {
			$hash[] = $value->to_hash();
		}
		return $hash;
	}

	public function __invoke() {
		if (func_num_args() == 0) {
			return $this->list;
		}
	}


	# ==================================================================================================

	protected function prepare_query() {
		$query = $this->definition['query'];
		# TODO: smarter logic to be able to handle more complex queries with preselects
		if (!$query) {
			$select = 'SELECT m.*';
			$from = ' FROM ' . $this->definition['class'] . ' m ';
			$query_suffix = '';
			foreach ($this->definition['filters'] as $key => $value) {
				$condition = '=';
				if (is_array($value)) {
					if (array_key_exists('condition', $value)) {
						$condition = $value['condition'];
					}
					$value = $value['value'];
				}
				if (!isset($value)) {
					$condition = 'IS NULL';
				}
				$query_suffix .= ($query_suffix ? ' AND ' : ' WHERE ') . $key . ' ' . $condition . (isset($value) ? " '" . pg_escape_string($this->c['db'],$value) . "'" : '' ) . '';
			}

			$counts = '';
			$index = 1;
			foreach ($this->definition['counts'] as $key => $value) {
				$column = str_replace('.', '_', $key);
				$select .= ", COALESCE(c$index.cnt, 0) AS $column";
				$counts = ($counts ? $counts : ' ') . " LEFT JOIN (SELECT $value, COUNT($value) AS cnt FROM $key GROUP BY $value) c$index ON m.$value = c$index.$value";
				$index++;
			}


			$ord = '';
			foreach ($this->definition['ordering'] as $key => $value) {
				$ord = ($ord ? $ord . ', ' : '') . $key . (strtolower($value) == 'desc' || $value == -1 ? ' DESC' : '');
			}

			$query = $select . $from . $counts . $query_suffix;
		}
		if ($ord) {
			$query .= ' ORDER BY ' . $ord;
		}
		if (isset($this->limit)) {
			$query .= ' LIMIT '.(isset($this->offset) ? $this->offset.', ' : '').$this->limit;
		}
		$this->query = $query;
		return $query;
	}
}

?>
