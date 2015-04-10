<?php
// BLP 2015-04-10 -- tested with 'test.php' and seems to work ok with 'pdo_sqlite'
// and 'pdo_pgsql'
/**
 * Database Class
 *
 * General PDO Database Class. 
 * @package Database
 * @author Barton Phillips <barton@bartonphillips.com>
 * @version 1.0
 * @link http://www.bartonphillips.com
 * @copyright Copyright (c) 2010, Barton Phillips
 * @license http://opensource.org/licenses/gpl-3.0.html GPL Version 3
 */

/**
 * @package Database
 */

class dbPdo extends dbAbstract {
  /**
   * PDO Database Link Identifier
   * @var resource $db
   */
  
  public $db = 0;

  protected $host, $user, $password, $database, $dbtype;
  private $result;
  
  /**
   * Constructor
   * @param string $host host name like 'mysql:dbname=testdb;host=127.0.0.1' etc.
   * @param string $user user name for database
   * @param string $password user's password for database
   * @param string $database name of the database
   * @param string $dbtype can be 'pdo_sqlite' or 'pdo_pgsql', 'mysql', 'mysqli'.
   *   Defaults to 'pdo_sqlite'.
   * as a side effect opens the database, that is connects and selects the database
   * NOTE: the Database class does not understand PDO mysql or mysqli it uses the native
   *   mysql drivers NOT PDO.
   */
  
  public function __construct($host, $user, $password, $database, $dbtype='pdo_sqlite') {
    if(preg_match("/^(.*?):(.*)$/", $host, $m)) {
      $host = $m[1];
      $post = $m[2];
    }
    
    switch($dbtype) {
      case 'mysql':
      case 'mysqli':
        $this->host = "mysql:dbname=$database;host=$host";
        break;
      case 'pdo_sqlite':
        $fulldsn = realpath($database);
        $this->host = "sqlite:$fulldsn";
        break;
      case 'pdo_pgsql':
        if(isset($port)) {
          $post = "port=$port;";
        } else $port = '';
        
        $this->host = "pgsql:host=$host;{$port}dbname=$database";
        break;
      default:
        $fulldsn = realpath($database);
        $this->host = "sqlite:$fulldsn";
    }
    $this->user = $user;
    $this->password = $password;
    $this->database = $database;
    $this->dbtype = $dbtype;
    $this->opendb();
  }
  
  /**
   * Connects and selects the database
   * @return resource link identifier
   * On Error outputs message and exits.
   */
  
  protected function opendb() {
    // Only do one open
    
    if($this->db) {
      return $this->db;
    }
    //echo "$this->host, $this->user, $this->password";
    try {
      $db = @new PDO($this->host, $this->user, $this->password);
    } catch(PDOException $e) {
      $this->errno = $e->getCode();
      $this->error = $e->getMessage();
      throw new SqlException(__METHOD__.  " Connection failed: $this->host", $this);
    }

    $this->db = $db; // set this right away so if we get an error below $this->db is valid

    return $db;
  }

  /**
   * Query database table
   * @param string $query SQL statement.
   * @return numrows 
   * On error calls SqlError() and exits.
   */

  public function query($query, $retarray=false) {
    $db = $this->opendb();

    if(!preg_match("/^(?:select)/i", $query)) {
      // These must use exec
      // echo "not select<br>";
      $numrows = $db->exec($query);

      if($numrows === false) {
        // error
        throw new SqlException($query, $this);
      }
      return $numrows;
    } else {
      if($this->result) {
        $this->result->closeCursor();
      }
            
      $result = $db->query($query);

      if($result === false) {
        $this->result = null;
        throw new SqlException($query, $this);
      }

      $this->result = $result;
      $numrows = $result->rowCount();
    }
    if($this->dbtype == 'pdo_sqlite') {
      $numrows = 1;
    }
    return $numrows;
  }

  /**
   * prepare()
   * PDO::prepare()
   * used as follows:
   * 1) $username="bob"; $query = "select one, two from test where name=?";
   * 2) $stm = PDO::prepare($query);
   * 3) $stm->bind_param("s", $username);
   * 4) $stm->execute();
   * 5) $stm->bind_result($one, $two);
   * 6) $stm->fetch();
   * 7) echo "one=$one, two=$two<br>";
   */
  
  public function prepare($query) {
    $db = $this->opendb();
    $stm = $db->prepare($query);
    return $stm;
  }

  /**
   * queryfetch()
   * Dose a query and then fetches the associated rows
   * Does a fetch_assoc() and places each row array into an array.
   * @param string, the query
   * @return array, the rows
   */
  
  public function queryfetch($query, $returnarray=false) {
    list($result, $numrows) = $this->query($query, true);
    
    if($result === false) {
      throw new SqlException($query, $this);
    }

    while($row = $result->fetch(PDO::FETCH_ASSOC)) {
      $rows[] = $row;
    }
    return ($returnarray) ? array($rows, $numrows, result=>$rows, numrows=>$numrows) : $rows;
  }

  /**
   * fetchrow()
   * @param resource identifier returned from query.
   * @param string, type of fetch: assoc==associative array, num==numerical array, or both
   * @return array, either assoc or numeric, or both
   * NOTE: if $result is a string then it is the type and we use $this->result for result.
   */
  
  public function fetchrow($result=null, $type="both") {
    if(is_string($result)) {
      $type = $result;
      $result = $this->result;
    } elseif(!$result) {
      $result = $this->result;
    }
    
    if(!$result) {
      throw new SqlException(__METHOD__ . ": result is null", $this);
    }
    
    switch($type) {
      case "assoc": // associative array
        return $result->fetch(PDO::FETCH_ASSOC);
      case "num":  // numerical array
        return $result->fetch(PDO::FETCH_NUM);
      case "both":
        return $result->fetch(PDO::FETCH_BOTH);
    }
  }

  /**
   * getLastInsertId()
   * NOTE: this does not work for Postgresql so if this returs null
   * use 'select lastval() ...' instead in your code.
   */

  public function getLastInsertId() {
    $db = $this->opendb();

    if($this->dbtype == 'pdo_pgsql') {
      return null;
    }
    return $db->lastInsertId();
  }
  
  /**
   * getNumRows()
   * NOTE: this does not work with sqlite so return 1
   */

  public function getNumRows($result=null) {
    if(!$result) {
      $result = $this->result;
    }

    if($this->dbtype == 'pdo_sqlite') {
      return 1;
    }
    return $result->rowCount();
  }

  // BLP 2015-04-10 -- added
  
  public function getResult() {
    return $this->result;
  }
  
  /**
   * Get the Database Resource Link Identifier
   * @return resource link identifier
   */
  
  public function getDb() {
    return $this->db;
  }

  public function getErrorInfo() {
    $err = $this->db->errorInfo(); // BLP 2015-04-10 -- this is a function not a prop
    $error = $err[2];
    $errno = $err[1];
    $err = array('errno'=>$errno, 'error'=>$error);
    return $err;
  }
  
  // real_escape_string
  
  public function escape($string) {
    if(get_magic_quotes_runtime()) {
      $string = stripslashes($string);
    }

    if(function_exists($this->db->real_escape_string)) {
      return $this->db->real_escape_string($string);
    } elseif(function_exists($this->db->quote)) {
      return $this->db->quote($string);
    } else {
      return $string;
    }
  }

  //
  
  public function escapeDeep($value) {
    if(is_array($value)) {
      foreach($value as $k=>$v) {
        $val[$k] = $this->escapeDeep($v);
      }
      return $val;
    } else {
      return $this->escape($value);
    }
  }

  public function __toString() {
    return __CLASS__;
  }
}

// ********************************************************************************
// END OF Database Class
// ********************************************************************************
