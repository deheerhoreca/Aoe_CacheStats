<?php

declare(strict_types=1);

class Aoe_CacheStats_Model_Cache extends Mage_Core_Model_Cache {
  const TYPE_LOAD_HIT  = "hit";
  const TYPE_LOAD_MEMHIT  = "memhit";
  const TYPE_LOAD_MISS = "miss";
  const TYPE_SAVE      = "save_key";
  const TYPE_REMOVE    = "remove_key";
  const TYPE_FLUSH     = "flush";
  const TYPE_CLEAN     = "clean_tag";
  
  protected $log = "";
  
  protected $pid;
  
  protected $loggingEnabled = null;
  
  /**
   * In-memory cache to reduce redundant small cache backend calls within the same request.
   * @var array<string,string|false>
   */
  protected $memCache = [];
  
  protected $maxMemCacheValSize = 1000;
  
  protected function getPid() {
    if (is_null($this->pid)) {
      $this->pid = getmypid();
    }
    return $this->pid;
  }
  
  /**
   * Load data from cache by id
   *
   * @param   string        $id
   * @return  string|false
   */
  public function load($id): string|false {
    $start = microtime(true) * 1000;
    
    if (isset($this->memCache[$id])) {
      $this->appendLog(
        self::TYPE_LOAD_MEMHIT,
        $id,
        round(microtime(true) * 1000 - $start, 2)
      );
      return $this->memCache[$id];
    }
    
    /** @var string|false $res */
    $res = parent::load($id);
    
    if (is_bool($res) || mb_strlen((string) $res) <= $this->maxMemCacheValSize) {
      $this->memCache[$id] = $res;
    }
    
    $this->appendLog(
      ($res === false) ? self::TYPE_LOAD_MISS : self::TYPE_LOAD_HIT,
      $id,
      round(microtime(true) * 1000 - $start, 2)
    );
    return $res;
  }
  
  /**
   * Save data
   *
   * @param  string          $data
   * @param  string          $id
   * @param  array           $tags
   * @param  null|false|int  $lifeTime
   *
   * @return bool
   */
  public function save($data, $id, $tags = [], $lifeTime = null) {
    $start = microtime(true) * 1000;
    $res   = parent::save($data, $id, $tags, $lifeTime);
    $this->appendLog(
      self::TYPE_SAVE,
      $id,
      round(microtime(true) * 1000 - $start, 2)
    );
    return $res;
  }
  
  /**
   * Remove cached data by identifier
   *
   * @param   string  $id
   * @return  bool
   */
  public function remove($id) {
    $start = microtime(true) * 1000;
    $res   = parent::remove($id);
    $this->appendLog(
      self::TYPE_REMOVE,
      $id,
      round(microtime(true) * 1000 - $start, 2)
    );
    return $res;
  }
  
  /**
   * Flush cached data
   *
   * @return  bool
   */
  public function flush() {
    $start = microtime(true) * 1000;
    $res = parent::flush();
    $this->appendLog(
      self::TYPE_FLUSH,
      "",
      round(microtime(true) * 1000 - $start, 2)
    );
    return $res;
  }
  
  /**
   * Clean cached data by specific tag
   *
   * @param   array|string $tags
   * @return  bool
   */
  public function clean($tags = []) {
    $start = microtime(true) * 1000;
    $tags = is_array($tags) ? $tags : [$tags];
    $res = parent::clean($tags);
    $this->memCache = []; // Clear memcache on any tag cleaning as we don't track which keys have which tags here
    $this->appendLog(
      self::TYPE_CLEAN,
      implode(", ", $tags),
      round(microtime(true) * 1000 - $start, 2)
    );
    return $res;
  }
  
  /**
   * Append a log entry, writing the log to disk every X messages to balance performance and observability during long jobs.
   *
   * Checks if logging is enabled, but does not set this flag itself because the config might not have been loaded yet.
   * By the time we start writing logs, the config should be available and the check is performed.
   *
   * @param  string  $type
   * @param  string  $id
   * @param  float   $duration
   *
   * @return void
   */
  protected function appendLog(string $type, string $id, float $duration): void {
    if ($this->loggingEnabled === false) {
      return;
    }
    static $counter = 0;
    $this->log .= sprintf("%s %s %s\n", str_pad($type, 12), str_pad($id, 70), $duration);
    if ($counter++ > 25) {
      $this->writeLogToFile();
      $counter = 0;
    }
  }
  
  /**
   * Write the accumulated log to file
   *
   * @return void
   */
  private function writeLogToFile(): void {
    if ($this->loggingEnabled === null) {
      $this->loggingEnabled = Mage::helper("core")->isModuleEnabled("Aoe_CacheStats");
      // $logMsg = str_repeat("=", 40)." Aoe_CacheStats logging ".($this->loggingEnabled ? "ENABLED" : "DISABLED")." ".str_repeat("=", 40)."\n";
      // file_put_contents(Mage::getBaseDir("var")."/log/aoe_cachestats.txt", $logMsg, FILE_APPEND);
    }
    if ($this->loggingEnabled === false) {
      return;
    }
    if ($this->log) {
      $httpVerb = Mage::app()?->getRequest()?->getMethod() ?? "cli";
      $currentAction = Mage::app()?->getFrontController()?->getAction()?->getFullActionName() ?? "unknown";
      
      static $wroteHeader = false;
      if (!$wroteHeader) {
        $logMsg = str_repeat("-", 25)." ".date("Y-m-d H:i:s")."  ".$this->getPid()."  {$httpVerb} {$currentAction}  ".$this->getCurrentUrl()." ".str_repeat("-", 25)."\n";
        file_put_contents(Mage::getBaseDir("var")."/log/aoe_cachestats.txt", $logMsg, FILE_APPEND);
      }
      $wroteHeader = true;
      
      file_put_contents(Mage::getBaseDir("var")."/log/aoe_cachestats.txt", $this->log, FILE_APPEND);
      $this->log = "";
    }
  }
  
  /**
   * Destructor - write log to file
   */
  public function __destruct() {
    $this->writeLogToFile();
  }
  
  /**
   * Get the current URL, decoded.
   *
   * @return string
   */
  private function getCurrentUrl(): string {
    return htmlspecialchars_decode(Mage::helper("core/url")->getCurrentUrl(), ENT_COMPAT | ENT_HTML5 | ENT_HTML401);
  }
}
