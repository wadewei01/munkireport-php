<?php

// Munkireport version (last number is number of commits)
$GLOBALS['version'] = '2.4.3.1156';

// Return version without commit count
function get_version()
{
	return preg_replace('/(.*)\.\d+$/', '$1', $GLOBALS['version']);
}

//===============================================
// Uncaught Exception Handling
//===============================================s
function uncaught_exception_handler($e)
{
  // Dump out remaining buffered text
  ob_end_clean();

  // Get error message
  error('Uncaught Exception: '.$e->getMessage());

  // Write footer
  die(View::do_fetch(conf('view_path').'partials/foot.php'));
}

function custom_error($msg='') 
{
	$vars['msg']=$msg;
	die(View::do_fetch(APP_PATH.'errors/custom_error.php',$vars));
}

//===============================================
// Alerts
//===============================================s

$GLOBALS['alerts'] = array();

/**
 * Add Alert
 *
 * @param string alert message
 * @param string type (danger, warning, success, info)
 **/
function alert($msg, $type="info")
{
	$GLOBALS['alerts'][$type][] = $msg;
}

/**
 * Add error message
 *
 * @param string message
 **/
function error($msg, $i18n = '')
{
	if( $i18n )
	{
		$msg = sprintf('<span data-i18n="%s">%s</span>', $i18n, $msg);
	}
	
	alert($msg, 'danger');
}

//===============================================
// Database
//===============================================

function getdbh()
{
	if ( ! isset($GLOBALS['dbh']))
	{
		try
		{
			$GLOBALS['dbh'] = new PDO(
				conf('pdo_dsn'),
				conf('pdo_user'),
				conf('pdo_pass'),
				conf('pdo_opts')
				);
		}
		catch (PDOException $e)
		{
			fatal('Connection failed: '.$e->getMessage());
		}

		// Set error mode
		$GLOBALS['dbh']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		
		// Store database name in config array
		if(preg_match('/.*dbname=([^;]+)/', conf('pdo_dsn'), $result))
		{
			$GLOBALS['conf']['dbname'] = $result[1];
		}
	}
	return $GLOBALS['dbh'];
}

//===============================================
// Autoloading for Business Classes
//===============================================
// module classes end with _model
function __autoload( $classname )
{
	// Switch to lowercase filename for models
	$classname = strtolower($classname);

	if(substr($classname, -4) == '_api')
	{
		require_once( APP_PATH.'modules/'.substr($classname, 0, -4).'/api'.EXT );
	}
	elseif(substr($classname, -6) == '_model')
	{
		$module = substr($classname, 0, -6);
		require_once( APP_PATH."modules/${module}/${module}_model".EXT );
	}
	else
	{
		require_once( APP_PATH.'models/'.$classname.EXT );
	}
}

function url($url='', $fullurl = FALSE)
{
  $s = $fullurl ? conf('webhost') : '';
  $s .= conf('subdirectory').($url && INDEX_PAGE ? INDEX_PAGE.'/' : INDEX_PAGE) . ltrim($url, '/');
  return $s;
}

/**
 * Return a secure url
 *
 * @param string url
 * @return string secure url
 * @author 
 **/
function secure_url($url = '')
{
	$parse_url = parse_url(url($url, TRUE));
	$parse_url['scheme'] = 'https';

	return 
		 ((isset($parse_url['scheme'])) ? $parse_url['scheme'] . '://' : '')
		.((isset($parse_url['user'])) ? $parse_url['user'] 
		.((isset($parse_url['pass'])) ? ':' . $parse_url['pass'] : '') .'@' : '')
		.((isset($parse_url['host'])) ? $parse_url['host'] : '')
		.((isset($parse_url['port'])) ? ':' . $parse_url['port'] : '')
		.((isset($parse_url['path'])) ? $parse_url['path'] : '')
		.((isset($parse_url['query'])) ? '?' . $parse_url['query'] : '')
		.((isset($parse_url['fragment'])) ? '#' . $parse_url['fragment'] : '')
        ;
}

function redirect($uri = '', $method = 'location', $http_response_code = 302)
{
	if ( ! preg_match('#^https?://#i', $uri))
	{
		$uri = url($uri);
	}
	
	switch($method)
	{
		case 'refresh'	: header("Refresh:0;url=".$uri);
			break;
		default			: header("Location: ".$uri, TRUE, $http_response_code);
			break;
	}
	exit;
}
/**
 * Lookup group id for passphrase
 *
 * @return integer group id
 * @author AvB
 **/
function passphrase_to_group($passphrase)
{
	$machine_group = new Machine_group;
	if( $machine_group->retrieve_one('property=? AND value=?', array('key', $passphrase)))
	{
		return $machine_group->groupid;
	}
	
	return 0;
}

/**
 * Check if current user may access data for serial number 
 *
 * @return boolean TRUE if authorized
 * @author 
 **/
function authorized_for_serial($serial_number)
{
	return id_in_machine_group(machine_computer_group($serial_number));
}

/**
 * Get machine computer_group
 *
 * @return integer computer group
 * @author AvB
 **/
function machine_computer_group($serial_number = '')
{
	if( ! isset($GLOBALS['machine_groups'][$serial_number]))
	{
		$machine = new Machine_model;
		if( $machine->retrieve_one('serial_number=?', $serial_number))
		{
			$GLOBALS['machine_groups'][$serial_number] = $machine->computer_group;
		}
		else
		{
			$GLOBALS['machine_groups'][$serial_number] = 0;
		}
	}

	return $GLOBALS['machine_groups'][$serial_number];
}

/**
 * Check if machine is member of machine_groups of current user
 * if no machine_groups defined, return TRUE
 *
 * @return void
 * @author 
 **/
function id_in_machine_group($id)
{
	if(isset($_SESSION['machine_groups']))
	{
		return in_array($id, $_SESSION['machine_groups']);
	}

	return TRUE;
}

/**
 * Get filter for machine_group membership
 *
 * @var string optional prefix default 'WHERE'
 * @var string how to address the machine table - default 'machine'
 * @return string filter clause
 * @author 
 **/
function get_machine_group_filter($prefix = 'WHERE', $machine_table_name = 'machine')
{
	if($groups = get_filtered_groups())
	{
		return sprintf('%s %s.computer_group IN (%s)', $prefix, $machine_table_name, implode(', ', $groups));
	}
	else
	{
		return '';
	}
}

/**
 * Get filtered groups
 *
 * @return void
 * @author 
 **/
function get_filtered_groups()
{
	$out = array();

	if(isset($_SESSION['machine_groups']))
	{
		if(isset($_SESSION['filter']['machine_group']) && $_SESSION['filter']['machine_group'])
		{
			$out = array_diff($_SESSION['machine_groups'], $_SESSION['filter']['machine_group']);
		}
		else
		{
			$out = $_SESSION['machine_groups'];
		}
	}
	return $out;
}

