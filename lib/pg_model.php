<?php

function class_autoload ($class) {
	global $AUTOLOAD_DIR;
	$dir = $AUTOLOAD_DIR ? $AUTOLOAD_DIR : __DIR__;
	if (class_exists($class, false)) {
		return true;
	}
	$parts = explode('\\', $class);
	$file = $dir . DIRECTORY_SEPARATOR . join(DIRECTORY_SEPARATOR, $parts) . '.php';
	if (is_file($file)) {
		include($file);
		return true;
	} else if ( sizeof($parts) > 1 ) {
		if (!class_exists('PgModel')) {
			include(__DIR__ . "/PgModel.php");
		}
		if (sizeof($parts) > 2 && $parts[2] == 'Listing') {
			$eval = 'namespace ' . $parts[0] . '\\' . $parts[1] . ';
			class ' . $parts[2] . ' extends \PgListing {}';
		} else {
			$eval = 'namespace ' . $parts[0] . ';
			class ' . $parts[1] . ' extends \PgModel {}';
		}
		eval($eval);
		return true;
	} else {
		throw new Exception("Unable to load $class.");
	}
}
spl_autoload_register("class_autoload");

?>