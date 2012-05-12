<?php
class Report {
	public $report;
	public $template;
	public $macros;
	public $options;
	public $is_ready;
	
	protected $mustache;
	
	protected $raw;
	protected $raw_headers;
	protected $raw_query;
	
	public function __construct($report,$macros = array(), $database = null) {
		$reportDir = PhpReports::$config['reportDir'];
		
		if(!file_exists($reportDir.'/'.$report)) {
			throw new Exception('Report not found');
		}
		
		$this->report = $report;
		
		//instantiate the templating engine
		$this->mustache = new Mustache;
		
		//get the raw report file and convert EOL to unix style
		$this->raw = file_get_contents($reportDir.'/'.$this->report);
		$this->raw = str_replace(array("\r\n","\r"),"\n",$this->raw);
		
		//if there are no headers in this report
		if(strpos($this->raw,"\n\n") === false) {
			throw new Exception('Report missing headers');
		}
		
		//split the raw report into headers and code
		list($this->raw_headers, $this->raw_query) = explode("\n\n",$this->raw,2);
		
		$this->macros = $macros;
		
		$this->parseHeader();
		
		$this->options['Database'] = $database;
		
		$this->initDb();
	}
	
	protected function parseHeader() {
		//default the report to being ready
		//if undefined variables are found in the headers, set to false
		$this->is_ready = true;
		
		$this->options = array(
			'Filters'=>array(),
			'Variables'=>array(),
			'Name'=>$this->report
		);
		
		$lines = explode("\n",$this->raw_headers);
		
		$first = true;
		foreach($lines as $line) {
			if(empty($line)) continue;
			
			//if the line doesn't start with a comment character, skip
			if(!in_array(substr($line,0,2),array('--','/*')) && $line[0] !== '#') continue;
			
			//remove comment from start of line and skip if empty
			$line = trim(ltrim($line,'-*/#'));
			if(!$line) continue;
			
			//if this is the first header and not in the format name:value, assume it is the report name
			if($first && strpos($line,':') === false) {
				$name = 'Name';
				$value = trim($line);
			}
			//if this is after the first header and not in the format name:value, assume it is part of the description
			elseif(strpos($line,':') === false) {				
				if(!isset($this->options['Description'])) $this->options['Description'] = '';
				$this->options['Description'] .= "\n".$line;
				continue;
			}
			else {
				list($name,$value) = explode(':',$line,2);
				$name = trim($name);
				$value = trim($value);
				
				//compatibility with legacy system
				if(strtoupper($name) === $name) $name = ucfirst(strtolower($name));
			}
			
			$first = false;
			
			//compatibility with legacy system
			if($name === 'Plot') $name = 'Chart';
			
			$classname = $name.'Header';
			if(class_exists($classname)) {
				$classname::parse($name,$value,$this);
			}
			else {
				throw new Exception("Unknown header $name");
			}
		}
		
		//try to infer report type from file extension
		if(!isset($this->options['Type'])) {
			$file_type = array_pop(explode('.',$this->report));
			switch($file_type) {
				case 'js':
					$this->options['Type'] = 'mongo';
					break;
				case 'sql':
					$this->options['Type'] = 'mysql';
					break;
				default:
					throw new Exception("Unknown report type");
			}
		}
	}
	
	protected function initDb() {
		//set up database connections
		switch($this->options['Type']) {
			case 'mysql':
				$mysql_connections = PhpReports::$config['mysql_connections'];
				
				//allow support for {macro} format as well as {{macro}} format (for compatibility with legacy systems)
				$this->raw_query = preg_replace('/([^\{])(\{[a-zA-Z0-9_\-]+\})([^\}])/','$1{$2}$3',$this->raw_query);
			
				//if the database isn't set or doesn't exist, use the first defined one
				if(!$this->options['Database'] || !isset($mysql_connections[$this->options['Database']])) {
					$this->options['Database'] = current(array_keys($mysql_connections));
				}
				
				//set up list of all available databases for displaying form for switching between them
				$this->options['Databases'] = array();
				foreach(array_keys($mysql_connections) as $name) {
					$this->options['Databases'][] = array(
						'selected'=>($this->options['Database'] === $name),
						'name'=>$name
					);
				}
				break;
			case 'mongo':
				$mongo_connections = PhpReports::$config['mongo_connections'];
			
				//if the database isn't set or doesn't exist, use the first defined one
				if(!$this->options['Database'] || !isset($mongo_connections[$this->options['Database']])) {
					$this->options['Database'] = current(array_keys($mongo_connections));
				}
				
				//set up list of all available databases for displaying form for switching between them
				$this->options['Databases'] = array();
				foreach(array_keys($mongo_connections) as $name) {
					$this->options['Databases'][] = array(
						'selected'=>($this->options['Database'] == $name),
						'name'=>$name
					);
				}
				break;
			case 'default':
				throw new Exception("Unknown report type");
		}
	}
	
	public function getRaw() {
		return $this->raw;
	}
	
	protected function openDb() {
		switch($this->options['Type']) {
			case 'mysql':
				$mysql_connections = PhpReports::$config['mysql_connections'];
				$config = $mysql_connections[$this->options['Database']];
				
				if(!($this->conn = mysql_connect($config['host'], $config['username'], $config['password']))) {
					throw new Exception('Could not connect to Mysql: '.mysql_error());
				}
				if(!mysql_select_db($config['database'])) {
					throw new Exception('Could not select Mysql database: '.mysql_error());
				}
				break;
		}
	}
	protected function closeDb() {
		if($this->options['Type'] === 'mysql') {
			mysql_close($this->conn);
		}
	}
	
	public function renderVariableForm($template='variable_form') {
		if(!file_exists('templates/'.$template.'.html')) {
			throw new Exception("Variable Form template now found");
		}
		
		if($this->options['Variables']) {
			$form = file_get_contents('templates/'.$template.'.html');
			
			$template_vars = array(
				'vars'=>array(),
				'database'=>$this->options['Database'],
				'databases'=>$this->options['Databases'],
				'report'=>$this->report
			);
			
			foreach($this->options['Variables'] as $var => $params) {
				if(!isset($params['name'])) $params['name'] = ucwords(str_replace(array('_','-'),' ',$var));
				if(!isset($params['type'])) $params['type'] = 'string';
				if(!isset($params['options'])) $params['options'] = false;
				$params['value'] = $this->macros[$var];
				$params['key'] = $var;
				
				if($params['type'] === 'select') {
					$params['is_select'] = true;
					
					foreach($params['options'] as $key=>$option) {
						if(!is_array($option)) {
							$params['options'][$key] = array(
								'display'=>$option,
								'value'=>$option
							);
						}
						if($params['options'][$key]['value'] == $params['value']) $params['options'][$key]['selected'] = true;
						else $params['options'][$key]['selected'] = false;
					}
				}
				
				$template_vars['vars'][] = $params;
			}
			
			return $this->mustache->render($form, $template_vars);
		}
		else return '';
	}
	
	public function runReport() {
		$this->openDb();
		
		if(!$this->is_ready) {
			throw new Exception("Report is not ready.  Missing variables");
		}
		
		$rows = array();
		$start = microtime(true);
		
		if($this->options['Type'] === 'mysql') {
			//expand macros in query
			$sql = $this->mustache->render($this->raw_query,$this->macros);
			
			$this->options['Query'] = $sql;
			
			$this->options['Query_Formatted'] = SqlFormatter::format($sql);
			
			//split queries and run each one, saving the last result
			$queries = explode(';',$sql);
			foreach($queries as $query) {
				//skip empty queries
				$query = trim($query);
				if(!$query) continue;
				
				$result = mysql_query($query);
				if(!$result) {
					throw new Exception("Query failed: ".mysql_error());
				}
			}
			
			while($row = mysql_fetch_assoc($result)) {
				$rows[] = $row;
			}
			
			$this->options['Time'] = round(microtime(true) - $start,5);
			$this->options['Count'] = count($rows);
			$this->options['Rows'] = $rows;
		}
		elseif($options['Type'] === 'mongo') {	
			throw new Exception("Not implemented");
			
			$eval = '';
			foreach($this->macros as $key=>$value) {
				$eval .= 'var '.$key.' = "'.addslashes($value).'";';
			}
			$command = 'mongo '.$mongo_connections[$config]['host'].':'.$mongo_connections[$config]['port'].'/'.$options['Database'].' --quiet --eval "'.addslashes($eval).'" '.$report;
			echo $command;
			
			$options['Query'] = $command;
		}
		else {
			throw new Exception("Unknown report type");
		}
		
		$this->closeDb();
	}
	
	protected function prepareRows() {
		$rows = array();
		$chart_rows = array();
		
		foreach($this->options['Rows'] as $row) {
			$rowval = array();
			$chartrowval = array();
			
			//if this is a total row and we're omitting totals from charts
			if(isset($this->options['Chart']) && isset($this->options['Chart']['omit-total']) && $this->options['Chart']['omit-total'] && trim(current($row))==='TOTAL') {
				$include_in_chart = false;
			}
			else {
				$include_in_chart = true;
			}
			
			$i=1;
			foreach($row as $key=>$value) {
				//determine if this column should appear in a chart
				$column_in_chart = false;
				if(!isset($this->options['Chart']['y'])) {
					$column_in_chart = true;
				}
				elseif(in_array($key,$this->options['Chart']['y']) || in_array($i,$this->options['Chart']['y'])) {
					$column_in_chart = true;
				}
				elseif($i===1 && !isset($this->options['Chart']['x'])) {
					$column_in_chart = true;
				}
				elseif(isset($this->options['Chart']['x']) && (in_array($key,$this->options['Chart']['x']) || in_array($i,$this->options['Chart']['x']))) {
					$column_in_chart = true;
				}
				
				//get filter fot column
				if(isset($this->options['Filters'][$key])) {
					$filter = $this->options['Filters'][$key]['filter'];
				}
				elseif(isset($this->options['Filters'][$i]['filter'])) {
					$filter = $this->options['Filters'][$i]['filter'];
				}
				else {
					$filter = false;
				}
				
				//get class for column
				if(isset($this->options['Columns'][$i-1])) {
					$class = $this->options['Columns'][$i-1];
				}
				else {
					$class = false;
				}
				
				//unescaped output
				if($class === 'raw') {
					$raw = true;
				}
				else {
					$raw = false;
				}
				
				//output wrapped in <pre> tags
				if($class === 'pre') {
					$pre = true;
				}
				else {
					$pre = false;
				}
				
				
				$alt = $value;
				
				if($filter) {
					$classname = $filter.'Filter';
					if(class_exists($classname)) {
						$value = $classname::filter($key,$value);
					}
				}
				
				if($column_in_chart) {
					$chartrowval[] = array(
						'key'=>$key,
						'value'=>$value, 
						'first'=>$i===1
					);
				}
				
				$rowval[] = array(
					'key'=>$key,
					'value'=>$value, 
					'alt'=>$alt, 
					'class'=>$class, 
					'first'=>$i===1,
					'raw'=>$raw,
					'pre'=>$pre
				);
				$i++;
			}
			
			if($include_in_chart) {
				$first = !$chart_rows;
				$chart_rows[] = array(
					'values'=>$chartrowval,
					'first'=>$first
				);
			}
			
			$first = !$rows;
			$rows[] = array(
				'values'=>$rowval,
				'first'=>$first
			);
		}
		
		$this->options['Rows'] = $rows;
		$this->options['ChartRows'] = $chart_rows;
	}
	
	public function renderReport() {
		$this->runReport();
		$this->prepareRows();
		
		if(isset($this->options['Template'])) $template = $this->options['Template'];
		else $template = 'table';
		
		if(!file_exists('templates/'.$template.'.html')) {
			throw new Exception("Report template not found");
		}
		
		$template_code = file_get_contents('templates/'.$template.'.html');
		
		//print_r($this->options);
		
		return $this->mustache->render($template_code, $this->options);
	}
}
?>