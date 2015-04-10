<?php
// BLP 2015-04-10 -- Tested with 'test.php' and seems to work OK

class dbPostgreSql extends dbAbstract{
  protected $host, $user, $password, $database;
  private $result; // for select etc. a result set.

  public function __construct($dbHost,$dbUser,$dbPass,$dbName=false){
    $this->host=$dbHost;
    $this->user=$dbUser;
    $this->pass=$dbPass;
    $this->database = $dbName;
    $this->opendb();
  }

  private function connectionString(){
    list($host, $port) = explode(':',$this->dbhost);
    
    $str='host='.$host;
    
    if($port)
      $str.=' port='.$port;

    if($this->dbuser)
      $str.=' user='.$this->dbuser;

    if($this->dbname)
      $str.=' dbname='.$this->dbname;

    if ($this->dbpass)
      $str.=' password='.$this->dbpass;

    return $str;
  }

  /**
   * Connects and selects the database
   * @return resource MySQL link identifier
   * On Error outputs message and exits.
   */
  
  protected function opendb() {
    // Only do one open
    
    if($this->db) {
      return $this->db;
    }
    
    $db = @pg_connect($cStr = $this->connectionString());

    if(!$db) {
      $this->errno = pg_last_error();
      $this->error = 'Connection Error : '.$cStr;
      throw new SqlException(__METHOD__ . ": Can't connect to database", $this);
    }
    
    $this->db = $db; // set this right away so if we get an error below $this->db is valid

    return $db;
  }

  /**
   * query()
   * Query database table
   * @param string $query SQL statement.
   * @return mixed result-set for select etc, true/false for insert etc.
   * On error calls SqlError() and exits.
   */

  public function query($query) {
    $db = $this->opendb();

    //self::$lastQuery = $query; // for debugging
    
    $result = pg_query($db, $query);

    if($result === false) {
      throw(new SqlException($query, $this));
    }
    if(!preg_match("/^(?:select)/i", $query)) {
      $numrows = pg_affected_rows($result);
      //self::$lastNonSelectResult = $result;
    } else {
      // NOTE: we don't change result for inserts etc. only for selects etc.
      $this->result = $result;
      $numrows = pg_num_rows($result);
    }

    return $numrows;
  }

  /**
   * prepare()
   * mysqli::prepare()
   * used as follows:
   * 1) $username="bob"; $query = "select one, two from test where name=?";
   * 2) $stm = mysqli::prepare($query);
   * 3) $stm->bind_param("s", $username);
   * 4) $stm->execute();
   * 5) $stm->bind_result($one, $two);
   * 6) $stm->fetch();
   * 7) echo "one=$one, two=$two<br>";
   */
/*  
  public function prepare($query) {
    $db = $this->opendb();
    $stm = $db->prepare($query);
    return $stm;
  }
*/
  
  /**
   * queryfetch()
   * Dose a query and then fetches the associated rows
   * Does a fetch_assoc() and places each row array into an array.
   * @param string, the query
   * @param string|null, if null then $type='both'
   * @param bool|null, if null then false.
   *   if param1, param2=bool then $type='both' and $returnarray=param2
   * @return array, the rows
   */
  
  public function queryfetch($query, $type=null, $returnarray=null) {
    if(stripos($query, 'select') === false) {
      throw new SqlException($query, $this);
    }
    
    if(is_null($type)) $type = 'both';
    elseif(is_bool($type) && is_null($returnarray)) {
      $returnarray = $type;
      $type = 'both';
    }  
                               
    $numrows = $this->query($query);

    while($row = $this->fetchrow($type)) {
      $rows[] = $row;
    }
    return ($returnarray) ? array('rows'=>$rows, 'numrows'=>$numrows) : $rows;
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
        $type = PGSQL_ASSOC;
        break;
      case "num":  // numerical array
        $type = PGSQL_NUM;
        break;
      case "both":
      default:
        $type = PGSQL_BOTH;
        break;
    }
    return pg_fetch_array($result, null, $type);
  }
  
  /**
   * getLastInsertId()
   * See the comments below. The bottom line is we should NEVER do multiple inserts
   * with a single insert command! You just can't tell what the insert id is. If we need to do
   * and 'insert ... on duplicate key' we better not need the insert id. If we do we should do
   * an insert in a try block and an update in a catch. That way if the insert succeeds we can
   * do the getLastInsertId() after the insert. If the insert fails for a duplicate key we do the
   * update in the catch. And if we need the id we can do a select to get it (somehow).
   * Note if the insert fails because we did a 'insert ignore ...' then last_id is zero and we return
   * zero.
   * @return the last insert id if this is done in the right order! Otherwise who knows.
   */
/* NOT REALLY AVAILABLE in PostgreSQL */
  
  public function getLastInsertId() {
    return null;
    
    $db = $this->opendb();
    // NOTE: if you have multiple items in an insert the insert_id is for the first one in the
    // group. For example: "insert into test (name) values('one'),('two'),('three')". The id field
    // is auto_increment. insert_id will be 1 if this is done right after the creation of the
    // table. But the last id is really 3. affected_rows is 3 so the last id is:
    // (insert_id + affected_rows -1)

    // $db->info shows:
    // Insert:  Records: 4  Duplicates: 0  Warnings: 0 // insert multipe records one statement
    // Update:  Rows matched: 2  Changed: 2  Warnings: 0 // update id in (100, 101) etc.
    // Insert/upsate: Records: 2 Duplicates: 1 Warnings: 0 // insert 2 records on duplicate key
    // update 1 record. So one straight insert in this case went info id 110, one insert that was a
    // duplicate so we did one (id 100 was already there) update. affected_rows is 3 here.
    // insert_id was 100 -- not sure why, I would have thought 110.
    // If the info says 'Rows matched:' then it is an update.
    // If the info says 'Records:' without any 'Duplicates:' then it is a insert/update
    // It also looks like $db->info is only filled in if $db->affected_rows is greater than one!
    
    // If the 'insert ignore ...' did in fact NOT do an insert then insert_id is zero and there
    // were no affected_rows so we need to test for that and return zero not -1.
    
    if($db->insert_id === 0) return 0;
    
    return ($db->insert_id + $db->affected_rows) -1;
  }
  
  /**
   * getNumRows()
   */

  public function getNumRows($result=null) {
    if(!$result) $result = $this->result;

    if(!preg_match("/^(?:select)/i", $query)) {
      $numrows = pg_affected_rows($result);
      //self::$lastNonSelectResult = $result;
    } else {
      // NOTE: we don't change result for inserts etc. only for selects etc.
      $this->result = $result;
      $numrows = pg_num_rows($result);
    }

    return $numrows;    
  }
  
  /**
   * Get the Database Resource Link Identifier
   * @return resource link identifier
   */
  
  public function getDb() {
    return $this->db;
  }

  public function getResult() {
    return $this->result;
  }

  public function getErrorInfo() {
    $error = $this->db->error;
    $errno = $this->db->errno;
    $err = array('errno'=>$errno, 'error'=>$error);
    return $err;
  }
  
  // real_escape_string
  
  public function escape($string) {
    $string = pg_escape_string(null, $string);
    return $string;
  }

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
