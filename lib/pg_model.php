<?php

function class_autoload ($class) {
	if (class_exists($class)) {
		return true;
	}
	if (is_file(__DIR__ . "/" . $class . ".php")) {
		include(__DIR__ . "/" . $class . ".php");
		return true;
	} else if ( preg_match('/\\\\/', $class) ) {
		if (!class_exists('PgModel')) {
			include(__DIR__ . "/PgModel.php");
		}
		$parts = explode('\\', $class);

		$eval = 'namespace ' . $parts[0] . ';
		class ' . $parts[1] . ' extends \PgModel {
			function __construct(&$c, $values = null) {
				parent::__construct($c, "' . str_replace('\\', '.', $class) . '", $values);
			}
		}';
		eval($eval);
		return true;
	} else {
		throw new Exception("Unable to load $class.");
	}
}
spl_autoload_register("class_autoload");

?>
