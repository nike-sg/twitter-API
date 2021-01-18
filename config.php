<?php 

if(isset($_GET['debug'])){
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}else{
  error_reporting(0);
  ini_set('display_errors', 0);
}

# DB Connection
require_once "db.php";


date_default_timezone_set('America/Sao_Paulo');