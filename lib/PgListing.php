<?php
class PgListing {
	var $definition = array (
		'query'    => '',
		'class'    => '',
		'columns'  => array (),
		'filters'  => array (), # key => value
		'ordering' => array (), # key => how
		'autoload' => true,
	);
	var $list    = array ();
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

	function __construct(&$config, $offset = null, $limit = null, $current = null, $filters = null) {
		$this->c = &$config;
		$this->offset = $offset;
		$this->limit  = $limit;
		$this->current['keys'] = $current;
		if ($filters) {
			$this->filter($filters);
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
			$this->definition['columns'][$name] = array (
				'type' => 'varchar',
			);
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
			$this->definition[filters] = $filters;
		} else {
			$this->definition[filters] = array ();
		}
		return $this;
	}

	function columns($columns = null) {
		if (is_array($columns)) {
			$this->definition[columns] = $columns;
		} else {
			$this->definition[columns] = array ();
		}
		return $this;
	}

	function order_by($ordering = null) {
		if (is_array($ordering)) {
			$this->definition['ordering'] = $ordering;
		} else {
			$this->definition['ordering'] = array ();
		}
		return $this;
	}

	function load() {
		if ($res = pg_query($this->c['db'],$this->prepare_query())) {
			$this->_count = 0;
			$class;
			$this->list = array ();
			if (isset($this->definition['class']) && ($this->definition['class'] != '')) {
				$class = $this->definition['class'];
			}
			while ($values = pg_fetch_assoc($res)) {
				if (is_array($this->current[keys]) && !sizeof(array_diff($values, $this->current[keys]))) {
					$this->current[index] = sizeof($this->list);
				}
				$value = new PgModel($this->c, $class);
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
		# TODO: chytrejsi logika na nahrazeni pouze na spravnem miste u slozitych dotazu s pre-selecty
		if (!$query) {
			$query = 'SELECT * FROM ' . $this->definition['class'] . ' ';
			$query_suffix = '';
			foreach ($this->definition['filters'] as $key => $value) {
				$condition = '=';
				if (is_array($value)) {
					if (array_key_exists('condition', $value)) {
						$condition = $value['condition'];
					}
					$value = $value['value'];
				}
				$query_suffix .= ($query_suffix ? ' AND ' : ' WHERE ') . $key . ' ' . $condition . (isset($value) ? " '" . pg_escape_string($this->c['db'],$value) . "'" : '' ) . '';
			}

			$ord = false;
			$ord2 = '';
			foreach ($this->definition['ordering'] as $key => $value) {
				$ord2 = ($ord2 ? $ord2 . ', ' : '') . $key . (strtolower($value) == 'desc' || $value == -1 ? ' DESC' : '');
			}
			if ($ord) {
				$query .= $ord;
			}

			$query .= $query_suffix;
		}
		if ($ord2) {
			$query .= ' ORDER BY ' . $ord2;
		}
		if (isset($this->limit)) {
			$query .= ' LIMIT '.(isset($this->offset) ? $this->offset.', ' : '').$this->limit;
		}
		$this->query = $query;
		return $query;
	}
}

?>
