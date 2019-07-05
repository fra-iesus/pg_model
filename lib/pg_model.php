<?php

function class_autoload ($class) {
	if (class_exists($class)) {
		return true;
	}
	$parts = explode('\\', $class);
	$file = __DIR__ . DIRECTORY_SEPARATOR . join(DIRECTORY_SEPARATOR, $parts) . '.php';
	if (is_file($file)) {
		include($file);
		return true;
	} else if ( sizeof($parts) > 1 ) {
		if (!class_exists('PgModel')) {
			include(__DIR__ . "/PgModel.php");
		}
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
