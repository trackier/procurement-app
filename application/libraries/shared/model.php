<?php

/**
 * Contains similar code of all models and some helpful methods
 *
 * @author Hemant Mann
 */

namespace Shared {
	use Task;
	use JsonSerializable;
	use \Shared\Services\Db;
	use Framework\{Registry, StringMethods, TimeZone};
	use Cloudstuff\ApiUtil\Api;

	class Model extends \Framework\Model implements JsonSerializable {
		const TASKS = [];
		const EVENT_CREATE = 'create';
		const EVENT_UPDATE = 'update';
		const EVENT_DELETE = 'delete';

		const TIMELINE_FIELDS = [];
		const TIMELINE_DISCARD_FIELDS = [];
		const TIMELINE_MAGIC_FIELDS = [];
		const TIMELINE_PRESERVE_FIELDS = [];

		const TABLE_DISPLAY_NAME = '';

		const STATUS_ACTIVE = 'active';
		const STATUS_PAUSED = 'paused';
		const STATUS_DELETED = 'deleted';
		const STATUS_PENDING = 'pending';

		/**
		 * This contains list of queries with fields to be removed from cache once object is modified
		 */
		const CACHE_KEYS = [
			['query' => ['_id' => '%s'], 'fields' => []]
		];

		protected $databaseDocument = [];

		/**
		 * To Store objects for lifetime of 1 Request/response cycle
		 * @var array
		 */
		protected $cacheBucket = [];
		
		/**
		 * @readwrite
		 * @var boolean
		 */
		protected $_allowNull = false;

		/**
		 * This array contains all the events that should not be fired for this object
		 * @var array
		 */
		public $blockedEvents = [];

		/**
		 * @read
		 */
		protected $_types = ["autonumber", "text", "integer", "decimal", "boolean", "datetime", "date", "time", "mongoid", "array", "mongoarray"];

		/**
		 * @column
		 * @readwrite
		 * @primary
		 * @type autonumber
		 * @label ID
		 */
		protected $__id = null;

		/**
		 * @column
		 * @readwrite
		 * @type boolean
		 * @index
		 */
		protected $_live = null;

		/**
		 * @column
		 * @readwrite
		 * @type datetime
		 * @label Created
		 */
		protected $_created = null;

		/**
		 * @column
		 * @readwrite
		 * @type datetime
		 */
		protected $_modified = null;

		/**
		 * @column
		 * @readwrite
		 * @type array
		 */
		protected $_meta = [];

		public function load() {
			
		}

		public function isNew() {
			return ! $this->_id;
		}

		/**
		 * Call this method when the json generated by the model should be such as it can
		 * be cross shared without any hassle
		 */
		public function simpleJson() {
			$columns = $this->getColumns();
			$arr = [];
			foreach ($columns as $key => $value) {
				$simpleValue = $this->$key;
				switch ($value['type']) {
					case 'mongoarray':
						$simpleValue = [];
						foreach (($this->$key ?? []) as $arrEl) {
							$simpleValue[] = Db::simplifyValue($arrEl);
						}
						break;

					case 'datetime':
					case 'time':
					case 'date':
						if (is_object($simpleValue) && is_a($simpleValue, 'DateTime')) {
							$simpleValue = $simpleValue->format('Y-m-d\TH:i:s.000\Z');
						}
						break;
				}
				$arr[$key] = $simpleValue;
			}
			return (object) $arr;
		}

		public function removeCache() {
			foreach (static::CACHE_KEYS as $arr) {
				$queryTemplate = $arr['query'];
				$fields = $arr['fields'] ?? [];
				$query = [];
				foreach ($queryTemplate as $key => $value) {
					if (is_null($value)) {
						$query[$key] = null;
					} else if (is_string($value)) {	// $value = '%s', // $key = model property
						$query[$key] = sprintf($value, $this->$key);
					} else {
						$query[$key] = $value;
					}
				}
				Utils::removeModelCache(get_class($this), $query, $fields);
			}
		}

		public function getTimelineChanges($event = self::EVENT_CREATE) {
			$notifyData = [];
			$objData = $this->toArray(['id', 'created', 'modified']);
			foreach (static::TIMELINE_MAGIC_FIELDS as $f) {
				$objData[$f] = $this->$f;	// this is magic property meaning custom getter and setter is defined for this property in the corresponding model
			}
			foreach ($objData as $key => $value) {
				if (in_array($key, static::TIMELINE_DISCARD_FIELDS)) {
					continue;
				}
				$oldValue = $this->getOldValue($key);
				$newValue = $value;
				$arr = [];
				switch ($event) {
					case self::EVENT_CREATE:
						if (! is_null($value)) {
							$arr['newValue'] = $value;	
						}
						break;
					
					case self::EVENT_UPDATE:
						if (is_object($oldValue) && is_object($newValue) && is_a($oldValue, 'DateTime') && is_a($newValue, 'DateTime')) {
							if ($oldValue->getTimestamp() != $newValue->getTimestamp()) {
								$arr = ['oldValue' => $oldValue->getTimestamp(), 'newValue' => $newValue->getTimestamp()];
							}
						} else if ($oldValue !== $newValue && !($oldValue == null && ($newValue == [] || $newValue == ""))) {
							$arr = ['oldValue' => $oldValue, 'newValue' => $newValue];
						} else if (in_array($key, static::TIMELINE_PRESERVE_FIELDS)) {
							$arr = ['newValue' => $newValue];
						}
						break;

					case self::EVENT_DELETE:
						if (! is_null($newValue)) {
							$arr = ['oldValue' => $newValue];	
						}
						break;
				}
				if (count($arr)) {
					$notifyData[$key] = $arr;	
				}
			}
			return $notifyData;
		}

		/**
		 * This function makes the timeline entry
		 * @param string $event Name of the event
		 * @param array $data Model Data array containing old values and new values
		 * @return string A well formed timeline string
		 */
		public static function makeTimelineString(string $event, array $data, $resource = []) {
		}

		/**
		 * This function inserts the timeline data for the model
		 * @param  string $event One of the event constants of Timeline
		 */
		public function insertTimeline($event) {
		}

		/**
		 * Make the Timeline Model data based on the current model resource
		 * @param  string $event Timeline Event - create, update, delete
		 * @return array        Assoc array having timeline model fields data
		 */
		public function makeTimelineData($event = null) {
			$data = [
				'resource' => static::getClassName(),
				'resource_id' => $this->_id,
				'event' => $event,
				'live' => true
			];
			$data = array_merge($data, Registry::getUserInfo());
			$userId = Registry::getCurrentUserId();
			if ($userId) {
				$data['user_id'] = $userId;
			}
			return $data;
		}

		public static function getClassName() {
			$name = static::class;
			$parts = explode("\\", $name);
			$len = count($parts);
			return $parts[$len - 1];
		}

		public function __toString() {
			return json_encode($this->toArray(['id']));
		}

		public function getIndexHint($query = []) {
			return null;
		}

		public function jsonSerialize() {
			$fields = static::fields();
			$arr = [];
			foreach ($fields as $f) {
				$arr[$f] = $this->$f;
			}
			$meta = $this->getMeta();
			$arr['meta'] = (object) $meta;
			$appConfig = Utils::getAppConfig();
			$unsetId = $opts['unset_id'] ?? $appConfig->model->object->unsetId;
			if (! $unsetId) {
				$arr['id'] = $this->getId();	
			}
			return $arr;
		}

		public function toArray($removeFields = []) {
			$arr = $this->jsonSerialize();
			foreach ($removeFields as $f) {
				unset($arr[$f]);
			}
			return $arr;
		}

		/**
		 * Use this function when we want to json encode models
		 * @param  array  $removeFields Fields to be removed from the current object
		 * @return array               Key value pair
		 */
		public function toJson($removeFields = []) {
			$arr = $this->toArray($removeFields);
			$arr['created'] = $this->created->getTimestamp();
			if ($this->modified && ! in_array('modified', $removeFields)) {
				$arr['modified'] = $this->modified->getTimestamp();
			}
			unset($arr['live']);
			unset($arr['meta']);
			return $arr;
		}

		public function getDataForCopy() {
			$data = $this->toArray(['id', '_id', 'modified']);
			$data['meta'] = (array) ($data['meta'] ?? []);
			$data['created'] = new \DateTime();
			return $data;
		}

		public function getTaskInfo($taskName = '') {
			if (!array_key_exists($taskName, static::TASKS)) {
				return '';
			}
			$task = Task::first(['object' => get_class($this), 'object_id' => $this->_id, 'name' => $taskName]);
			if (!$task) return '';

			$dt = TimeZone::zoneConverter($task->execution_time);
			$dateOnly = $dt->format('F j\, o');
			$timeOnly = $dt->format('g\:i a');
			return sprintf("%s %s at %s", static::TASKS[$taskName]['info'], $dateOnly, $timeOnly);
		}

		public function createTask($name, $time = null, $org = null) {
			if (!array_key_exists($name, static::TASKS)) {
				throw new \Exception("Task does not exists!!");
			}

			// First check if task already exists
			$search = ['name' => $name, 'object' => get_class($this), 'object_id' => $this->_id];
			$task = Task::first($search);
			if ($task) {
				return false;
			}
			return $search;
		}

		public function deleteTask($name = '') {
			if (!$name) {
				return false;
			}
			$task = Task::first(['object' => get_class($this), 'object_id' => $this->_id, 'name' => $name]);
			if ($task) {
				$task->delete();	
			}
			return true;
		}

		public static function fields($exclude = []) {
			$model = get_called_class();
			$cl = "\\" . $model;
			$m = new $cl;
			$columns = $m->getColumns();
			$fields = array_keys($columns);

			$ans = [];
			foreach ($fields as $f) {
				if (!in_array($f, $exclude)) {
					$ans[] = $f;    
				}
			}

			return $ans;
		}

		public function setLive($val) {
			$this->_live = (boolean) $val;
		}

		public static function hourly() {
			// override this method to do cron tasks
		}

		public function display() {
			$columns = $this->getColumns();
			$arr = [];
			foreach ($columns as $key => $value) {
				$field = "_{$key}";
				$arr[$key] = $this->$field;
			}
			return $arr;
		}

		public static function modifyQuery($query, $extra = []) {
			$fields = static::fields();
			foreach ($extra as $key => $value) {
				if (in_array($key, $fields)) {
					$query[$key] = $value;	
				}
			}
			return $query;
		}

		public static function displayImg($name = '', $folder = "images") {
			return Utils::media($name, 'display');
		}

		public function &getMeta() {
			if (is_null($this->_meta)) {
				$this->_meta = [];
			}
			$this->_meta = (array) $this->_meta;
			return $this->_meta;
		}

		/**
		 * Converts array of \Shared\Model objects to array of stdClass objects
		 * Also converts DateTime class to simple string, pass an extra key in
		 * the last parameter to stop this datetime conversion
		 * @return array         Array of Objects
		 */
		public static function objectArr($arr = [], $fields = [], $opts = []) {
           if (!is_array($arr)) {
				$newArr = [];
				$newArr[] = $arr;
				$arr = $newArr;
			}
			if (count($fields) === 0) {
				$fields = static::fields();
			}
          
			
			$results = [];
			foreach ($arr as $key => $a) {
				$data = [];
				foreach ($fields as $f) {
					$convert = $opts['convert'] ?? true;
					$convertProp = $a->$f ?? null;
					if ($convert && $convertProp && is_object($a->$f) && is_a($a->$f, 'DateTime')) {
						$dtObject = TimeZone::zoneConverter($a->$f, $opts);
						$dateTime = $opts['date_time'] ?? false;
						if ($dateTime) {
							$data[$f] = $dtObject->format('Y-m-d H:i:s');
						} else {
							$data[$f] = $dtObject->format('Y-m-d');
						}
					} else {
						$data[$f] = $a->$f ?? null;
					}
				}
				if (!isset($data['id'])) $data['id'] = $a->_id ?? null;

				$appConfig = Utils::getAppConfig();
				$unsetId = $opts['unset_id'] ?? $appConfig->model->object->unsetId;
				if ($unsetId) unset($data['id']);

				if (in_array('meta', $fields)) {
					$data['meta'] = (object) $data['meta'];
				}
				
				$includeKey = $opts['include_key'] ?? $appConfig->model->object->includeKey;
				$obj = (object) $data;
				if (@($a->_id === $key) && $includeKey) {
					$results[$key] = $obj;
				} else {
					$results[] = $obj;
				}
			}
			return $results;
		}

		public function makeDBDocument($generateId = false) {
			$primary = $this->getPrimaryColumn();
			$raw = $primary["raw"];
			$collection = $this->getCollection();

			$doc = []; $columns = $this->getColumns();
			foreach ($columns as $key => $value) {
				$field = $value['raw'];
				$current = $this->$field;
				
				$v = $this->_convertToType($current, $value['type']);
				$checkEmpty = $this->_preventEmpty($v, $value['type']);
				
				$allowNull = $this->getOldValue($key) != $current;
				if (is_null($checkEmpty)) {
					// check when to save null values
					if ($allowNull) {
						$doc[$key] = null;
					}
				} else {
					$doc[$key] = $v;
				}
			}
			if ($generateId) {
				$doc['_id'] = Db::generateId(false);
			}
			$this->databaseDocument = $doc;
		}

		public function getDatabaseDocument($generateId = false) {
			$this->makeDBDocument($generateId);
			return $this->databaseDocument;
		}

		public function updateWithoutNull($replace = false) {
			$id = $this->_id;
			$this->_id = null;
			$doc = $this->getDatabaseDocument();
			$doc['modified'] = Db::time();
			$collection = $this->getCollection();
			if ($replace) {
				$doc['_id'] = Db::convertType($id);
				$collection->replaceOne(['_id' => Db::convertType($id)], $doc);
			} else {
				$collection->updateOne(['_id' => Db::convertType($id)], ['$set' => $doc]);
			}
			$this->modified = new \DateTime();
			$this->_id = $id;
		}

		/**
		 * Every time a row is created these fields should be populated with default values.
		 */
		public function save() {
           $primary = $this->getPrimaryColumn();
			$raw = $primary["raw"];
			$collection = $this->getCollection();

			$doc = []; $columns = $this->getColumns();
			foreach ($columns as $key => $value) {
				$field = $value['raw'];
				$current = $this->$field;
				
				$v = $this->_convertToType($current, $value['type']);
				$checkEmpty = $this->_preventEmpty($v, $value['type']);
				
				$allowNull = $this->getOldValue($key) != $current;
				if (is_null($checkEmpty)) {
					// check when to save null values
					if ($allowNull) {
						$doc[$key] = null;
					}
				} else {
					$this->$field = $v;
					$doc[$key] = $v;
				}
			}
			unset($doc['_id']); // this step is necessary

			$timelineEvent = $this->isNew() ? self::EVENT_CREATE : self::EVENT_UPDATE;

			if (empty($this->$raw)) {
				if (isset($doc['created'])) {
					if (Db::isType($doc['created'], 'date')) {
						$this->_created = $doc['created'];
					} else if (is_string($doc['created'])) {
						$this->_created = $doc['created'] = Db::time($doc['created']);
					}
				} else {
					$this->_created = $doc['created'] = Db::time();
				}

				$result = $collection->insertOne($doc);
				$this->__id = $result->getInsertedId();
			} else {
				$this->_modified = $doc['modified'] = Db::time();

				$this->__id = Db::convertType($this->__id);
				$result = $collection->updateOne(['_id' => $this->__id], ['$set' => $doc]);
			}

			// remove BSON Types from class because they prevent it from
			// being serialized and store into the session
			foreach ($columns as $key => $value) {
				$raw = "_{$key}"; $val = $this->$raw;
				$this->$raw = Db::simplifyValue($val);
			}
			$this->removeCache();

			// after successful save fire timeline entry
			$this->insertTimeline($timelineEvent);
		}

		protected function _preventEmpty($value, $type) {
			switch ($type) {
				case 'integer':
					if ($value === 0) {
						$value = null;
					}
					break;
				
				case 'array':
				case 'mongoarray':
					if (count($value) === 0) {
						$value = null;
					}
					break;

				case 'decimal':
					if ($value === 0.0) {
						$value = null;
					}
					break;

				case 'text':
					if ($value === '') {
						$value = null;
					}
					break;

				case 'mongoid':
					if (is_string($value) && strlen(trim($value)) === 0) {
						$value = null;
					}
					break;
			}
			return $value;
		}

		/**
		 * @important | @core function
		 * Specific types are needed for MongoDB for proper querying
		 * @param misc $value
		 * @param string $type
		 */
		public function _convertToType($value, $type) {
			if (Db::isType($value, 'regex')) {
				return $value;
			}

			switch ($type) {
				case 'text':
					if (!is_array($value) && ! is_null($value)) {
						$value = (string) $value;
					}
					break;

				case 'integer':
					if (! is_array($value)) {
						$value = (int) $value;
					}
					break;

				case 'boolean':
					if (!is_array($value)) {
						$value = (boolean) $value;
					}
					break;

				case 'decimal':
					if (! is_array($value)) {
						$value = (float) $value;
					}
					break;

				case 'datetime':
				case 'date':
					if (is_array($value)) {
						break;
					} else if (is_object($value)) {
						if (Db::isType($value, 'date')) {
						   break;
						} else if (is_a($value, 'DateTime')) {
							$dt = TimeZone::zoneConverter($value, ['zone' => 'UTC']);
							$value = Db::time($dt->getTimestamp());
						} else {
							$value = Db::time();
						}
					} else if (is_string($value)) {
						$value = Db::time($value);
					}
					break;

				case 'autonumber':
				case 'mongoid':
					if (Db::isType($value, 'id')) {
						break;
					} else if (is_array($value)) {
						$copy = $value; $value = [];
						foreach ($copy as $key => $val) {
							$value[$key] = Db::convertType($val);
						}
					} else {
						$value = Db::convertType($value);
					}
					break;

				case 'mongoarray':
					if (is_array($value) && count($value)) {
						if (array_key_exists(Db::OPERATOR_EQUALS, $value) || array_key_exists(Db::OPERATOR_IN, $value) || array_key_exists(Db::OPERATOR_ELEM_MATCH, $value)) {
							break;
						}
						$value = Db::convertType($value);
					} else {
						$value = [];
					}
					break;

				case 'array':
					if (!is_array($value)) {
						$value = (array) $value;   
					}
					break;
				
				default:
					$value = $value;
					break;
			}
			return $value;
		}

		/**
		 * @getter
		 * @return \MongoDB\Collection Collection object of MongoDB
		 */
		public function getCollection() {
			$table = $this->getTable();
			$collection = Registry::get("MongoDB")->$table;
			return $collection;
		}

		/**
		 * @getter
		 * Returns "_id" if presents else "__id"
		 */
		public function getId() {
			if (property_exists($this, '_id')) {
				return $this->_id;
			}
			return $this->__id;
		}

		/**
		 * Updates the MongoDB query
		 */
		public function _updateQuery($where) {
			$columns = $this->getColumns();

			$query = [];
			foreach ($where as $key => $value) {
				$key = str_replace('=', '', $key);
				$key = str_replace('?', '', $key);
				$key = preg_replace("/\s+/", '', $key);

				// because $this->id equivalent to $this->_id
				if ($key == "id" && !property_exists($this, '_id')) {
					$key = "_id";
				}

				if (isset($columns[$key])) {
					$query[$key] = $this->_convertToType($value, $columns[$key]['type']);   
				} else {
					$query[$key] = $value;
				}
			}
			return $query;
		}

		/**
		 * Updates the fields when query mongodb
		 * Checks for correct property "id" and "_id"
		 * Also accounts for "*" in MySql
		 */
		public function _updateFields($fields) {
			$f = [];
			if (!is_array($fields)) return $f;
			foreach ($fields as $key => $value) {
				if ($value == "*" || !is_string($value)) {
					continue;
				}

				if ($value == "id" && !property_exists($this, '_id')) {
					$f["_id"] = 1;
				} else {
					$f[$value] = 1;
				}
			}
			return $f;
		}

		public static function selectAll($where = [], $fields = [], $findOpts = []) {
			$model = new static();
			$where = $model->_updateQuery($where);
			$fields = $model->_updateFields($fields);

			$collection = $model->getCollection();
			list($order, $direction, $limit, $page, $maxTimeMS) = Db::getLimitOpts($findOpts);
			$opts = Db::opts($fields, $order, $direction, $limit, $page);
			if ($maxTimeMS) {
				$opts['maxTimeMS'] = $maxTimeMS;
			}

			$cursor = $collection->find($where, $opts);
			$results = [];
			foreach ($cursor as $c) {
				$converted = $model->_convert($c);
				if ($converted->_id) {
					$key = Db::simplifyValue($converted->_id);
					$results[$key] = $converted;
				} else {
					$results[] = $converted;
				}
			}
			return $results;
		}

		/**
		 * @param array $where ['name' => 'something'] OR ['name = ?' => 'something'] (both works)
		 * @param array $fields ['name' => true, '_id' => true]
		 * @param string $order Name of the field
		 * @param int $direction 1 | -1 OR "asc" |  "desc"
		 * @param int $limit
		 * @return array
		 */
		public static function all($where = [], $fields = [], $order = null, $direction = null, $limit = null, $page = null) {
			$model = new static();
			$where = $model->_updateQuery($where);
			$fields = $model->_updateFields($fields);
			return $model->_all($where, $fields, $order, $direction, $limit, $page);
		}

		protected function _all($where = [], $fields = [], $order = null, $direction = null, $limit = null, $page = null) {
			$collection = $this->getCollection();

			$opts = Db::opts($fields, $order, $direction, $limit, $page);

			$cursor = $collection->find($where, $opts);
			$results = [];
			foreach ($cursor as $c) {
				$converted = $this->_convert($c);
				if ($converted->_id) {
					$key = Db::simplifyValue($converted->_id);
					$results[$key] = $converted;
				} else {
					$results[] = $converted;
				}
			}
			return $results;
		}

		/**
		 * Wrapper for static::first query just for more productivity
		 * @param  string $id The resource ID
		 * @return $this|null
		 */
		public static function findById($id) {
			return static::first(['_id' => $id]);
		}

		/**
		 * @param array $where ['name' => 'something'] OR ['name = ?' => 'something'] (both works)
		 * @param array $fields ['name' => true, '_id' => true]
		 * @param string $order Name of the field
		 * @param int $direction 1 | -1 OR "asc" |  "desc"
		 * @param int $limit
		 * @return $this|null
		 */
		public static function first($where = [], $fields = [], $order = null, $direction = null) {
			$model = new static();
			$where = $model->_updateQuery($where);
			$fields = $model->_updateFields($fields);
			return $model->_first($where, $fields, $order, $direction);
		}

		protected function _first($where = [], $fields = [], $order = null, $direction = null) {
			$collection = $this->getCollection();
			$record = null;

			if ($order && $direction) {
				$results = self::_all($where, $fields, $order, $direction, 1);
				
				if (count($results) === 1) {
					$record = array_shift($results);   
				}
			} else {
				if (count($fields) === 0) {
					$record = $collection->findOne($where);
				} else {
					$record = $collection->findOne($where, ['projection' => $fields]);
				}

				$record = $this->_convert($record);    
			}

			return $record;
		}

		/**
		 * Converts the MongoDB result to an object of class 
		 * whose parent is \Shared\Model
		 */
		protected function _convert($record) {
			if (!$record) return null;
			$columns = $this->getColumns();
			$record = (array) $record;

			$class = get_class($this);
			$c = new $class();
			$data = [];

			foreach ($record as $key => $value) {
				if (!property_exists($this, "_{$key}")) {
					continue;
				}
				$raw = "_{$key}";
				$data[$key] = $c->$raw = Db::simplifyValue($value);
			}
			$c->setOldValues($data);
			
			return $c;
		}

		/**
		 * Find the records of the table and if none found then sets a
		 * flash message to the session and redirects if $opts['redirect']
		 * is set else returns the records
		 */
		public static function isEmpty($query = [], $fields = [], $opts = []) {
			$records = self::all($query, $fields);
			$session = Registry::get("session");

			if (count($records) === 0) {
				if (isset($opts['msg'])) {
					$session->set('$flashMessage', $opts['msg']);
				}

				if (isset($opts['redirect'])) {
					$controller = $opts['controller'];
					$controller->redirect($opts['redirect']);
				}
			}
			return $records;
		}

		public function delete() {
			// Fire delete event before actually removing the data
			$this->insertTimeline(self::EVENT_DELETE);
			$collection = $this->getCollection();

			$query = $this->_updateQuery(['_id' => $this->__id]);
			$return = $collection->deleteOne($query);
		}

		public static function deleteAll($query = []) {
			$instance = new static();
			$query = $instance->_updateQuery($query);
			$collection = $instance->getCollection();
			if (! $query) {
				throw new \Exception("Delete query can not be empty");
			}

			$return = $collection->deleteMany($query);
		}

		/**
		 * This function creates multiple objects found from the query
		 * @param  array  $query The query to be passed to ::all
		 * @return null
		 */
		public static function deleteMany(array $query) {
			$arr = static::all($query);
			foreach ($arr as $obj) {
				$obj->delete();
			}
		}

		public static function updateAll($query = [], $setObj = []) {
			$instance = new static();
			$query = $instance->_updateQuery($query);
			$collection = $instance->getCollection();

			$return = $collection->updateMany($query, ['$set' => $setObj]);
		}

		public static function optimizedCount($query = [], $opts = []) {
			$model = new static();
			$query = $model->_updateQuery($query);
			$collection = $model->getCollection();

			return $collection->count($query, $opts);
		}

		public static function count($query = []) {
			$model = new static();
			$query = $model->_updateQuery($query);
			return $model->_count($query);
		}

		public static function cacheCount($query = [], $opts = []) {
			$cacheKey = static::getCacheKey($query, []) . "_cc";
			$foundCache = Utils::getCache($cacheKey, false);
			$byPassCache = $opts['bypass_cache'] ?? false;
			if ($foundCache === false || $byPassCache) {
				if ($opts) {
					unset($opts['bypass_cache']);
					$foundCache = static::optimizedCount($query, $opts);
				} else {
					$foundCache = static::count($query);
				}
				Utils::setCache($cacheKey, $foundCache);
			}
			return $foundCache;
		}

		protected function _count($query = []) {
			$collection = $this->getCollection();

			$count = $collection->count($query);
			return $count;
		}

		public static function cacheFirst($where = [], $fields = [], $order = null, $direction = null) {
			$cacheKey = static::getCacheKey($where, $fields);
			$foundCache = Utils::getCache($cacheKey, false);	// false (item not in cache), null (item in cache but with a null value), any (Model) [item exists in the cache]

			if ($foundCache === false) {
				$foundCache = static::first($where, $fields, $order, $direction);
				Utils::setCache($cacheKey, $foundCache);
			}
			return $foundCache;
		}

		public static function cacheAll($where = [], $fields = [], $order = null, $direction = null, $limit = null, $page = null) {
			$cacheKey = static::getCacheKey($where, $fields);
			$foundCache = Utils::getCache($cacheKey, false);

			if ($foundCache === false) {
				$foundCache = static::all($where, $fields, $order, $direction, $limit, $page);
				Utils::setCache($cacheKey, $foundCache);
			}
			return $foundCache;
		}

		/**
		 * [PUBLIC] This static method same as cacheAll but with maxTimeMS option supported.
		 * @author Abhay Chauhan (@code-dagger)
		 */
		public static function cacheAllv2($where = [], $fields = [], $findOpts = []) {
			$cacheKey = static::getCacheKey($where, $fields);
			$foundCache = Utils::getCache($cacheKey, false);
			if ($foundCache === false) {
				$foundCache = static::selectAll($where, $fields, $findOpts);
				Utils::setCache($cacheKey, $foundCache);
			}
			return $foundCache;
		}

		public static function postRequest($fullUrl, $token ,$data) {
			$api = new Api([ 'defaultTimeout' => 20 ]);
			return $api->post($fullUrl, [
				'headers' => [
					'x-jwt-token' => $token
				],
				'json' => $data
			]);
		}
	}
}
