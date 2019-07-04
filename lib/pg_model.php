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

		if (sizeof($parts) > 2 && $parts[2] == 'Listing') {
			$eval = 'namespace ' . $parts[0] . '\\' . $parts[1] . ';
			class ' . $parts[2] . ' extends \PgListing {
				function __construct(&$c, $params = []) {
					$params["class"] = "' . $parts[0] . '.' . $parts[1] . '";
					parent::__construct($c, $params);
				}
			}';
		} else {
			$eval = 'namespace ' . $parts[0] . ';
			class ' . $parts[1] . ' extends \PgModel {
				function __construct(&$c, $values = null) {
					parent::__construct($c, "' . str_replace('\\', '.', $class) . '", $values);
				}
			}';
		}
		eval($eval);
		return true;
	} else {
		throw new Exception("Unable to load $class.");
	}
}
spl_autoload_register("class_autoload");

?>
