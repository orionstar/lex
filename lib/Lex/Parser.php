<?php declare(strict_types=1);
/**
 * Part of the Lex Template Parser.
 *
 * @author     Dan Horrigan
 * @license    MIT License
 * @copyright  2011 - 2012 Dan Horrigan
 */

namespace Lex;

use Closure;
use IteratorAggregate;

class Parser {

	protected bool   $allowPhp          = false;
	protected bool   $regexSetup        = false;
	protected string $scopeGlue         = '.';
	protected bool   $cumulativeNoparse = false;

	protected bool $inCondition = false;

	protected string $variableRegex     = '';
	protected string $variableLoopRegex = '';
	protected string $variableTagRegex  = '';

	protected string $noparseRegex = '';

	protected string $conditionalRegex     = '';
	protected string $conditionalElseRegex = '';
	protected string $conditionalEndRegex  = '';
	/**
	 * @var null|array|object
	 */
	protected $conditionalData = [];

	protected static array $extractions = [
		'noparse' => [],
	];

	/**
	 * @var null|array|object
	 */
	protected static $data;
	/**
	 * @var null|array|object
	 */
	protected static $callbackData;

	protected string $callbackNameRegex;
	protected string $callbackBlockRegex;
	protected string $recursiveRegex;
	protected string $conditionalExistsRegex;
	protected string $conditionalNotRegex;

	/**
	 * The main Lex parser method.  Essentially acts as dispatcher to
	 * all of the helper parser methods.
	 *
	 * @param string       $text     Text to parse
	 * @param array|object $data     Array or object to use
	 * @param null|Closure $callback Callback to use for Callback Tags
	 *
	 * @throws \Lex\ParsingException
	 */
	public function parse(string $text, $data = [], ?Closure $callback = null, bool $allowPhp = false): string
	{
		$this->setupRegex();
		$this->allowPhp = $allowPhp;

		// Only convert objects to arrays
		if(is_object($data))
		{
			$data = $this->toArray($data);
		}

		// Is this the first time parse() is called?
		if(self::$data === null)
		{
			// Let's store the local data array for later use.
			self::$data = $data;
		}
		else
		{
			// Let's merge the current data array with the local scope variables
			// So you can call local variables from within blocks.
			$data = array_merge(self::$data, $data);

			// Since this is not the first time parse() is called, it's most definately a callback,
			// let's store the current callback data with the the local data
			// so we can use it straight after a callback is called.
			self::$callbackData = $data;
		}

		// The parseConditionals method executes any PHP in the text, so clean it up.
		if( ! $allowPhp)
		{
			$text = str_replace(['<?', '?>'], ['&lt;?', '?&gt;'], $text);
		}

		$text = $this->parseComments($text);
		$text = $this->extractNoparse($text);
		$text = $this->extractLoopedTags($text, $data, $callback);

		// Order is important here.  We parse conditionals first as to avoid
		// unnecessary code from being parsed and executed.
		$text = $this->parseConditionals($text, $data, $callback);
		$text = $this->injectExtractions($text, 'looped_tags');
		$text = $this->parseVariables($text, $data, $callback);
		$text = $this->injectExtractions($text, 'callback_blocks');

		if($callback)
		{
			$text = $this->parseCallbackTags($text, $data, $callback);
		}

		// To ensure that {{ noparse }} is never parsed even during consecutive parse calls
		// set $cumulativeNoparse to true and use self::injectNoparse($text); immediately
		// before the final output is sent to the browser
		if( ! $this->cumulativeNoparse)
		{
			$text = $this->injectExtractions($text);
		}

		return $text;
	}

	/**
	 * Removes all of the comments from the text.
	 *
	 * @param string $text Text to remove comments from
	 */
	public function parseComments(string $text): string
	{
		$this->setupRegex();

		return preg_replace('/{{#.*?#}}/s', '', $text);
	}

	/**
	 * Recursivly parses all of the variables in the given text and
	 * returns the parsed text.
	 *
	 * @param string       $text Text to parse
	 * @param array|object $data Array or object to use
	 *
	 * @throws \Lex\ParsingException
	 */
	public function parseVariables(string $text, $data, ?Closure $callback = null): string
	{
		$this->setupRegex();

		/**
		 * $data_matches[][0][0] is the raw data loop tag
		 * $data_matches[][0][1] is the offset of raw data loop tag
		 * $data_matches[][1][0] is the data variable (dot notated)
		 * $data_matches[][1][1] is the offset of data variable
		 * $data_matches[][2][0] is the content to be looped over
		 * $data_matches[][2][1] is the offset of content to be looped over
		 */
		if(preg_match_all($this->variableLoopRegex, $text, $data_matches, PREG_SET_ORDER + PREG_OFFSET_CAPTURE))
		{
			foreach($data_matches as $match)
			{
				if($loop_data = $this->getVariable($match[1][0], $data))
				{
					$looped_text = '';
					if(is_array($loop_data) || ($loop_data instanceof IteratorAggregate))
					{
						foreach($loop_data as $item_data)
						{
							$str = $this->extractLoopedTags($match[2][0], $item_data, $callback);
							$str = $this->parseConditionals($str, $item_data, $callback);
							$str = $this->injectExtractions($str, 'looped_tags');
							$str = $this->parseVariables($str, $item_data, $callback);

							if($callback !== null)
							{
								$str = $this->parseCallbackTags($str, $item_data, $callback);
							}

							$looped_text .= $str;
						}
					}
					$text = preg_replace('/' . preg_quote($match[0][0], '/') . '/m', addcslashes($looped_text, '\\$'), $text, 1);
				}
				else
				{ // It's a callback block.
					// Let's extract it so it doesn't conflict
					// with the local scope variables in the next step.
					$text = $this->createExtraction('callback_blocks', $match[0][0], $match[0][0], $text);
				}
			}
		}

		/**
		 * $data_matches[0] is the raw data tag
		 * $data_matches[1] is the data variable (dot notated)
		 */
		if(preg_match_all($this->variableTagRegex, $text, $data_matches))
		{
			foreach($data_matches[1] as $index => $var)
			{
				if(($val = $this->getVariable($var, $data, '__lex_no_value__')) !== '__lex_no_value__')
				{
					$text = str_replace($data_matches[0][$index], $val, $text);
				}
			}
		}

		return $text;
	}

	/**
	 * Parses all Callback tags, and sends them through the given $callback.
	 *
	 * @param string $text     Text to parse
	 * @param mixed  $data
	 * @param Closure $callback Callback to apply to each tag
	 *
	 * @throws \Lex\ParsingException
	 */
	public function parseCallbackTags(string $text, $data, Closure $callback): string
	{
		$this->setupRegex();
		$inCondition = $this->inCondition;

		if($inCondition)
		{
			$regex = '/{\s*(' . $this->variableRegex . ')(\s+.*?)?\s*}/ms';
		}
		else
		{
			$regex = '/{{\s*(' . $this->variableRegex . ')(\s+.*?)?\s*(\/)?}}/ms';
		}

		/**
		 * $match[0][0] is the raw tag
		 * $match[0][1] is the offset of raw tag
		 * $match[1][0] is the callback name
		 * $match[1][1] is the offset of callback name
		 * $match[2][0] is the parameters
		 * $match[2][1] is the offset of parameters
		 * $match[3][0] is the self closure
		 * $match[3][1] is the offset of closure
		 */
		while(preg_match($regex, $text, $match, PREG_OFFSET_CAPTURE))
		{
			$selfClosed = isset($match[3]);
			$parameters = [];
			[$tag, $start] = $match[0];
			$name = $match[1][0];

			if(isset($match[2]))
			{
				$cb_data = $data;
				if( ! empty(self::$callbackData))
				{
					$data = $this->toArray($data);
					$cb_data = array_merge(self::$callbackData, $data);
				}

				$raw_params = $this->injectExtractions($match[2][0], '__cond_str');
				$parameters = $this->parseParameters($raw_params, $cb_data, $callback);
			}

			$content = '';

			$temp_text = substr($text, $start + strlen($tag));
			if( ! $selfClosed && preg_match('/{{\s*\/' . preg_quote($name, '/') . '\s*}}/m', $temp_text, $match, PREG_OFFSET_CAPTURE))
			{

				$content = substr($temp_text, 0, $match[0][1]);
				$tag .= $content . $match[0][0];

				// Is there a nested block under this one existing with the same name?
				$nested_regex = '/{{\s*(' . preg_quote($name, '/') . ')(\s.*?)}}(.*?){{\s*\/\1\s*}}/ms';
				if(preg_match($nested_regex, $content . $match[0][0], $nested_matches))
				{
					$nested_content = preg_replace('/{{\s*\/' . preg_quote($name, '/') . '\s*}}/m', '', $nested_matches[0]);
					$content = $this->createExtraction('nested_looped_tags', $nested_content, $nested_content, $content);
				}
			}

            $replacement = $callback($name, $parameters, $content);
            $replacement = $this->parseRecursives($replacement, $content, $callback);

			if($inCondition)
			{
				$replacement = $this->valueToLiteral($replacement);
			}
			
			$text = preg_replace('/' . preg_quote($tag, '/') . '/m', addcslashes($replacement, '\\$'), $text, 1);
			$text = $this->injectExtractions($text, 'nested_looped_tags');
		}

		return $text;
	}

	/**
	 * Parses all conditionals, then executes the conditionals.
	 *
	 * @param string $text     Text to parse
	 * @param mixed  $data     Data to use when executing conditionals
	 * @param null|Closure $callback The callback to be used for tags
	 *
	 * @throws \Lex\ParsingException
	 */
	public function parseConditionals(string $text, $data, ?Closure $callback): string
	{
		$this->setupRegex();
		preg_match_all($this->conditionalRegex, $text, $matches, PREG_SET_ORDER);

		$this->conditionalData = $data;

		/**
		 * $matches[][0] = Full Match
		 * $matches[][1] = Either 'if', 'unless', 'elseif', 'elseunless'
		 * $matches[][2] = Condition
		 */
		foreach($matches as $match)
		{
			$this->inCondition = true;

			$condition = $match[2];

			// Extract all literal string in the conditional to make it easier
			if(preg_match_all('/(["\']).*?(?<!\\\\)\1/', $condition, $str_matches))
			{
				foreach($str_matches[0] as $m)
				{
					$condition = $this->createExtraction('__cond_str', $m, $m, $condition);
				}
			}

			$condition = preg_replace($this->conditionalNotRegex, '$1!$2', $condition);

			if(preg_match_all($this->conditionalExistsRegex, $condition, $existsMatches, PREG_SET_ORDER))
			{
				foreach($existsMatches as $m)
				{
					$exists = 'true';
					if($this->getVariable($m[2], $data, '__doesnt_exist__') === '__doesnt_exist__')
					{
						$exists = 'false';
					}

					$condition = $this->createExtraction('__cond_exists', $m[0], $m[1] . $exists . $m[3], $condition);
				}
			}

			$condition = preg_replace_callback('/\b(' . $this->variableRegex . ')\b/', [$this, 'processConditionVar'], $condition);

			if($callback)
			{
				$condition = preg_replace('/\b(?!{\s*)(' . $this->callbackNameRegex . ')(?!\s+.*?\s*})\b/', '{$1}', $condition);
				$condition = $this->parseCallbackTags($condition, $data, $callback);
			}

			// Re-extract the strings that have now been possibly added.
			if(preg_match_all('/(["\']).*?(?<!\\\\)\1/', $condition, $str_matches))
			{
				foreach($str_matches[0] as $m)
				{
					$condition = $this->createExtraction('__cond_str', $m, $m, $condition);
				}
			}

			// Re-process for variables, we trick processConditionVar so that it will return null
			$this->inCondition = false;
			$condition = preg_replace_callback('/\b(' . $this->variableRegex . ')\b/', [$this, 'processConditionVar'], $condition);
			$this->inCondition = true;

			// Re-inject any strings we extracted
			$condition = $this->injectExtractions($condition, '__cond_str');
			$condition = $this->injectExtractions($condition, '__cond_exists');

			$conditional = '<?php ';

			if($match[1] === 'unless')
			{
				$conditional .= 'if ( ! (' . $condition . '))';
			}
			else if($match[1] === 'elseunless')
			{
				$conditional .= 'elseif ( ! (' . $condition . '))';
			}
			else
			{
				$conditional .= $match[1] . ' (' . $condition . ')';
			}

			$conditional .= ': ?>';

			$text = preg_replace('/' . preg_quote($match[0], '/') . '/m', addcslashes($conditional, '\\$'), $text, 1);
		}

		$text = preg_replace($this->conditionalElseRegex, '<?php else: ?>', $text);
		$text = preg_replace($this->conditionalEndRegex, '<?php endif; ?>', $text);

		$text = $this->parsePhp($text);
		$this->inCondition = false;

		return $text;
	}

	/**
	 * Goes recursively through a callback tag with a passed child array.
	 *
	 * @param string $text      - The replaced text after a callback.
	 * @param string $orig_text - The original text, before a callback is called.
	 *
	 * @throws \Lex\ParsingException
	 */
	public function parseRecursives(string $text, string $orig_text, ?Closure $callback): string
	{
		// If {{ *recursive [array_key]* }} tag missing...
		if( ! preg_match($this->recursiveRegex, $text, $match))
		{
			return $text;
		}

		// Is there a {{ *recursive [array_key]* }} tag here, let's loop through it.
		[$array_key, $tag] = $match;
		$next_tag = null;
		$children = self::$callbackData[$array_key];
		$child_count = count($children);
		$count = 1;

		// Is the array not multi-dimensional? Let's make it multi-dimensional.
		if($child_count === count($children, COUNT_RECURSIVE))
		{
			$children = [$children];
			$child_count = 1;
		}

		foreach($children as $child)
		{
			$has_children = true;

			// If this is a object let's convert it to an array.
			$child = $this->toArray($child);

			// Does this child not contain any children?
			// Let's set it as empty then to avoid any errors.
			if( ! array_key_exists($array_key, $child))
			{
				$child[$array_key] = [];
				$has_children = false;
			}

			$replacement = $this->parse($orig_text, $child, $callback, $this->allowPhp);

			// If this is the first loop we'll use $tag as reference, if not
			// we'll use the previous tag ($next_tag)
			$current_tag = $next_tag ?? $tag;

			// If this is the last loop set the next tag to be empty
			// otherwise hash it.
			$next_tag = ($count === $child_count) ? '' : md5($tag . $replacement);

			$text = str_replace($current_tag, $replacement . $next_tag, $text);

			if($has_children)
			{
				$text = $this->parseRecursives($text, $orig_text, $callback);
			}

			$count++;
		}

		return $text;
	}

	/**
	 * Gets or sets the Scope Glue
	 *
	 * @param string|null $glue The Scope Glue
	 */
	public function scopeGlue(?string $glue = null): string
	{
		if($glue !== null)
		{
			$this->regexSetup = false;
			$this->scopeGlue = $glue;
		}

		return $this->scopeGlue;
	}

	/**
	 * Sets the noparse style. Immediate or cumulative.
	 */
	public function cumulativeNoparse(bool $mode): void
	{
		$this->cumulativeNoparse = $mode;
	}

	/**
	 * This is used as a callback for the conditional parser.  It takes a variable
	 * and returns the value of it, properly formatted.
	 *
	 * @param mixed $match A match from preg_replace_callback
	 */
	protected function processConditionVar($match): string
	{
		$var = is_array($match) ? $match[0] : $match;

		if(is_numeric($var) ||
			strpos($var, '__cond_str') === 0 ||
			strpos($var, '__cond_exists') === 0 ||
			in_array(strtolower($var), ['true', 'false', 'null', 'or', 'and']))
		{
			return $var;
		}

		$value = $this->getVariable($var, $this->conditionalData, '__processConditionVar__');

		if($value === '__processConditionVar__')
		{
			return $this->inCondition ? $var : 'null';
		}

		return $this->valueToLiteral($value);
	}

	/**
	 * This is used as a callback for the conditional parser.  It takes a variable
	 * and returns the value of it, properly formatted.
	 *
	 * @param array $match A match from preg_replace_callback
	 */
	protected function processParamVar(array $match): string
	{
		return $match[1] . $this->processConditionVar($match[2]);
	}

	/**
	 * Takes a value and returns the literal value for it for use in a tag.
	 *
	 * @param mixed $value Value to convert
	 */
	protected function valueToLiteral($value): string
	{
		if(is_object($value) && is_callable([$value, '__toString']))
		{
			return var_export((string) $value, true);
		}

		if(is_array($value))
		{
			return ! empty($value) ? "true" : "false";
		}

		return var_export($value, true);
	}

	/**
	 * Sets up all the global regex to use the correct Scope Glue.
	 */
	protected function setupRegex(): void
	{
		if($this->regexSetup)
		{
			return;
		}

		$glue = preg_quote($this->scopeGlue, '/');

		$this->variableRegex = $glue === '\\.' ? '[a-zA-Z0-9_' . $glue . ']+' : '[a-zA-Z0-9_\.' . $glue . ']+';
		$this->callbackNameRegex = $this->variableRegex . $glue . $this->variableRegex;
		$this->variableLoopRegex = '/{{\s*(' . $this->variableRegex . ')\s*}}(.*?){{\s*\/\1\s*}}/ms';
		$this->variableTagRegex = '/{{\s*(' . $this->variableRegex . ')\s*}}/m';

		$this->callbackBlockRegex = '/{{\s*(' . $this->variableRegex . ')(\s.*?)}}(.*?){{\s*\/\1\s*}}/ms';

		$this->recursiveRegex = '/{{\s*\*recursive\s*(' . $this->variableRegex . ')\*\s*}}/ms';

		$this->noparseRegex = '/{{\s*noparse\s*}}(.*?){{\s*\/noparse\s*}}/ms';

		$this->conditionalRegex = '/{{\s*(if|unless|elseif|elseunless)\s*(\(?(.*?)\)?)\s*}}/ms';
		$this->conditionalElseRegex = '/{{\s*else\s*}}/ms';
		$this->conditionalEndRegex = '/{{\s*endif\s*}}/ms';
		$this->conditionalExistsRegex = '/(\s+|^)exists\s+(' . $this->variableRegex . ')(\s+|$)/ms';
		$this->conditionalNotRegex = '/(\s+|^)not(\s+|$)/ms';

		$this->regexSetup = true;

		// This is important, it's pretty unclear by the documentation
		// what the default value is on <= 5.3.6
		ini_set('pcre.backtrack_limit', '1000000');
	}

	/**
	 * Extracts the noparse text so that it is not parsed.
	 *
	 * @param string $text The text to extract from
	 */
	protected function extractNoparse(string $text): string
	{
		/**
		 * $matches[][0] is the raw noparse match
		 * $matches[][1] is the noparse contents
		 */
		if(preg_match_all($this->noparseRegex, $text, $matches, PREG_SET_ORDER))
		{
			foreach($matches as $match)
			{
				$text = $this->createExtraction('noparse', $match[0], $match[1], $text);
			}
		}

		return $text;
	}

	/**
	 * Extracts the looped tags so that we can parse conditionals then re-inject.
	 *
	 * @param string $text The text to extract from
	 * @param mixed  $data
	 * @param mixed  $callback
	 *
	 * @throws \Lex\ParsingException
	 */
	protected function extractLoopedTags(string $text, $data = [], ?Closure $callback = null): string
	{
		/**
		 * $matches[][0] is the raw match
		 */
		if(preg_match_all($this->callbackBlockRegex, $text, $matches, PREG_SET_ORDER))
		{
			foreach($matches as $match)
			{
				// Does this callback block contain parameters?
				if($this->parseParameters($match[2], $data, $callback))
				{
					// Let's extract it so it doesn't conflict with local variables when
					// parseVariables() is called.
					$text = $this->createExtraction('callback_blocks', $match[0], $match[0], $text);
				}
				else
				{
					$text = $this->createExtraction('looped_tags', $match[0], $match[0], $text);
				}
			}
		}

		return $text;
	}

	/**
	 * Extracts text out of the given text and replaces it with a hash which
	 * can be used to inject the extractions replacement later.
	 *
	 * @param string $type        Type of extraction
	 * @param string $extraction  The text to extract
	 * @param string $replacement Text that will replace the extraction when re-injected
	 * @param string $text        Text to extract out of
	 */
	protected function createExtraction(string $type, string $extraction, string $replacement, string $text): string
	{
		$hash = md5($replacement);
		self::$extractions[$type][$hash] = $replacement;

		return str_replace($extraction, "{$type}_$hash", $text);
	}

	/**
	 * Injects all of the extractions.
	 *
	 * @param string $text Text to inject into
	 * @param string $type Text to inject into
	 */
	protected function injectExtractions(string $text, string $type = ''): string
	{
		if($type === null)
		{
			foreach(self::$extractions as $extraction_type => $extractions)
			{
				foreach($extractions as $hash => $replacement)
				{
					if(strpos($text, "{$extraction_type}_$hash") !== false)
					{
						$text = str_replace("{$extraction_type}_$hash", $replacement, $text);
						unset(self::$extractions[$extraction_type][$hash]);
					}
				}
			}
		}
		else
		{
			if( ! isset(self::$extractions[$type]))
			{
				return $text;
			}

			foreach(self::$extractions[$type] as $hash => $replacement)
			{
				if(strpos($text, "{$type}_$hash") !== false)
				{
					$text = str_replace("{$type}_$hash", $replacement, $text);
					unset(self::$extractions[$type][$hash]);
				}
			}
		}

		return $text;
	}

	/**
	 * Takes a dot-notated key and finds the value for it in the given
	 * array or object.
	 *
	 * @param string       $key     Dot-notated key to find
	 * @param array|object $data    Array or object to search
	 * @param null|string  $default Default value to use if not found
	 * @return mixed
	 */
	protected function getVariable(string $key, $data, ?string $default = null)
	{
		if(strpos($key, $this->scopeGlue) === false)
		{
			$parts = explode('.', $key);
		}
		else
		{
			$parts = explode($this->scopeGlue, $key);
		}

		foreach($parts as $key_part)
		{
			if(is_array($data))
			{
				if( ! array_key_exists($key_part, $data))
				{
					return $default;
				}

				$data = $data[$key_part];
			}
			else if(is_object($data))
			{
				if( ! isset($data->{$key_part}))
				{
					return $default;
				}

				$data = $data->{$key_part};
			}
			else
			{
				return $default;
			}
		}

		return $data;
	}

	/**
	 * Evaluates the PHP in the given string.
	 *
	 * @param string $text Text to evaluate
	 *
	 * @throws \Lex\ParsingException
	 */
	protected function parsePhp(string $text): string
	{
		ob_start();
		$result = eval('?>' . $text . '<?php ');

		if($result === false)
		{
			$output = 'You have a syntax error in your Lex tags. The offending code: ';
			throw new ParsingException($output . str_replace(['?>', '<?php '], '', $text));
		}

		return ob_get_clean();
	}


	/**
	 * Parses a parameter string into an array
	 *
	 * @param string $parameters The string of parameters
	 * @param mixed  $data
	 *
	 * @throws \Lex\ParsingException
	 */
	protected function parseParameters(string $parameters, $data, ?Closure $callback): array
	{
		$this->conditionalData = $data;
		$this->inCondition = true;

		// Extract all literal string in the conditional to make it easier
		if(preg_match_all('/(["\']).*?(?<!\\\\)\1/', $parameters, $str_matches))
		{
			foreach($str_matches[0] as $m)
			{
				$parameters = $this->createExtraction('__param_str', $m, $m, $parameters);
			}
		}

		$parameters = preg_replace_callback(
			'/(.*?\s*=\s*(?!__))(' . $this->variableRegex . ')/is',
			[$this, 'processParamVar'],
			$parameters
		);

		if($callback)
		{
			$parameters = preg_replace('/(.*?\s*=\s*(?!{\s*)(?!__))(' . $this->callbackNameRegex . ')(?!\s*})\b/', '$1{$2}', $parameters);
			$parameters = $this->parseCallbackTags($parameters, $data, $callback);
		}

		// Re-inject any strings we extracted
		$parameters = $this->injectExtractions($parameters, '__param_str');
		$this->inCondition = false;

		if(preg_match_all('/(.*?)\s*=\s*(\'|"|&#?\w+;)(.*?)(?<!\\\\)\2/s', trim($parameters), $matches))
		{
			$return = [];
			foreach($matches[1] as $i => $attr)
			{
				$return[trim($matches[1][$i])] = stripslashes($matches[3][$i]);
			}

			return $return;
		}

		return [];
	}

	/**
	 * Convert objects to arrays
	 *
	 * @param mixed $data
	 */
	public function toArray($data = []): array
	{
		if($data instanceof Arrayable)
		{
			$data = $data->toArray();
		}

		// Objects to arrays
		is_array($data) || $data = (array) $data;

		// lower case array keys
		return array_change_key_case($data, CASE_LOWER);
	}

}
