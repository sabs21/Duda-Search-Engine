<?php

// This file will not check whether there is an entry that already exists with the same base_url.
// Prior logic must determine that this site is not currently within the database.

/////////////////////////
// PRE-INITIALIZATION //
///////////////////////

// Begin timer
$begin = round(microtime(true) * 1000);
set_time_limit(120);

// Override PHP.ini so that errors do not display on browser.
ini_set('display_errors', 0);

////////////////////////
// CLASS DEFINITIONS //
//////////////////////

// This contains the search phrase typed by the user and the url searched from.
class Phrase {
    protected $phrase;
    protected $url;

    public function __construct($phrase, $url) {
        $this->phrase = $phrase;
        $this->url = $url;
    }

    public function get_phrase() {
        return $this->phrase;
    }

    public function set_phrase($new_phrase) {
        $this->phrase = $new_phrase;
    }

    public function get_url() {
        return $this->url;
    }

    public function set_url($new_url) {
        $this->url = $new_url;
    }
}

class Result {
    public $url;
    public $title;
    public $snippet;

    public function __construct($url, $title, $snippet) {
        $this->url = $url;
        $this->title = $title;
        $this->snippet = $snippet;
    }
}

class RelevanceBin {
    protected $bins;
    protected $maximums; // Highest dupes for each term.
    protected $relevance_arr;
    
    public function __construct() {
        $this->bins = [];
        $this->maximums = [];
        $this->relevance_arr = [];
    }

    public function get_bins() {
        return $this->bins;
    }

    // Each bin holds the relevancy score of a given page.
    // If a bin exists for the given page_id, then add the new value to the existing value. 
    // If a bin does not exist for the given page_id, create one and set its value.
    public function add_bin($page_id, $value) {
        /*if (!isset($this->bins[$page_id])) {
            $this->bins[$page_id] = 0;
        }*/
        $this->bins[$page_id][] = $value;
    }

    // Store the highest dupe count of a given term.
    // These maximums will be used to calculate an average of sorts
    // This is STEP 1 of creating the relevance array.
    public function add_max($max) {
        $this->maximums[] = $max;
    }

    // Divide each bin with maximums to calculate the relevance of each page
    // This is STEP 2 of creating the relevance array.
    public function create_relevance_arr() {
        $page_ids = array_keys($this->bins);

        //$i = 0;
        foreach($page_ids as $page_id) {
            //foreach($this->maximums as $max) {
                //$this->bins[$page_id][]
            //}
            $relevance = 0;
            // The amount of bins each page contains is the same as the amount of search terms and is also the same as the amount of maximum dupe counts.
            for ($i = 0; $i < count($this->bins[$page_id]); $i++) {
                /*if (!isset($this->bins[$page_id]['relevance'])) {
                    $this->bins[$page_id]['relevance'] = 0;
                }*/
                $relevance += ceil(($this->bins[$page_id][$i] / $this->maximums[$i]) * 100);
            }
            $this->relevance_arr[$page_id] = $relevance;
            //$i += 1;
        }

        return $this->relevance_arr;
    }

    public function get_relevance_arr() {
        return $this->relevance_arr;
    }
}

/////////////////////
// INITIALIZATION //
///////////////////

// Use this array as a basic response object. May need something more in depth in the future.
$response = [
    'searchPhrase' => NULL,
    'searchTerms' => NULL,
    'results' => NULL,
    'totalResults' => NULL,
    'totalPages' => NULL,
    'page' => $page_to_return + 1,
    'relevance_arr' => NULL,
    'matched' => NULL,
];

// Get data from the POST sent from the fetch API
$raw = trim(file_get_contents('php://input'));
$url = json_decode($raw)->url;
$phrase = json_decode($raw)->phrase;
$page_to_return = json_decode($raw)->page - 1; // This value will be used as an array index, so we subtract 1.

// Remove unnecessary characters and seperate phrase into seperate terms
$phrase = sanitize($phrase, ['symbols' => true, 'lower' => false, 'upper' => false]);
$response['searchPhrase'] = $phrase;
$terms = explode(' ', $phrase);
$response['searchTerms'] = $terms;

// Format the url which was recieved so that it does not end in '/'
if ($url[strlen($url) - 1] == '/') {
    $url = substr($url, 0, strlen($url) - 1);
}

// Use this array as a basic response object. May need something more in depth in the future.
// Prepares a response to identify errors and successes.
/*$response = [
  'time_taken' => 0,
  'found_site_id' => false,
  'search_phrase' => NULL,
  'search_terms' => NULL,
  //'bins' => NULL,
  'search_results' => NULL,
  //'ordered_by_relevance' => NULL,
  'pdo_error' => NULL,
  'db_error' => NULL,
  'misc' => NULL
];*/

///////////////////////////////////////////////
// CHECK N' GUESS THE INTENDED SEARCH TERMS //
/////////////////////////////////////////////

// Use wordSorted to find the word to make sure it's spelled right. 
// If the term is not in the dictionary, it's spelled wrong.
$path = "./wordSorted.json";

$json = file_get_contents($path);
$dict = json_decode($json, TRUE);

foreach($terms as $term) {
    $matchIndex = binarySearchWord($dict, 0, count($dict) - 1, $term);
    $response['matched'][] = $dict[$matchIndex]['word'];
    //$response['matched'] = $dict[$matchIndex]->word;
}


// For terms that are spelled wrong, use metaphoneSorted to find any possible matches.
// We want to find the word with the same metaphone and the shortest Levenshtein distance.



//////////////////////
// SEARCH DATABASE //
////////////////////

// Get credentials for database
$rawCreds = file_get_contents("../credentials.json");
$creds = json_decode($rawCreds);

$username = $creds->username;
$password = $creds->password;
$serverIp = $creds->server_ip;
$dbname = $creds->database_name;
$dsn = "mysql:dbname=".$dbname.";host=".$serverIp;

// Create a new PDO instance
try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Errors placed in "S:\Program Files (x86)\XAMPP\apache\logs\error.log"
} 
catch (PDOException $e) {
    $response['pdo_error'] = 'Connection failed: ' . $e->getMessage();
} 
catch(Exception $e) {
    $response['pdo_error'] = $e->getMessage();
}

// Communicate with the database
try {
    if (!isset($pdo)) {
        throw new Exception("PDO instance is not defined.");
    }

    // Grab relevant site_id from recent call
    $pdo->beginTransaction();
    $sql = 'SELECT site_id FROM sites WHERE url = ?';
    $statement = $pdo->prepare($sql);
    $statement->execute([$url]);
    $sql_res = $statement->fetch(); // Returns an array of indexed and associative results. Indexed is preferred.
    $site_id = $sql_res[0];

    // Detect the success of pulling the site_id from the database.
    //$response['found_site_id'] = true;

    // Remove unnecessary characters and seperate phrase into seperate terms
    $phrase = sanitize($phrase, ['symbols' => true, 'lower' => false, 'upper' => false]);
    $response['searchPhrase'] = $phrase;
    $terms = explode(' ', $phrase);
    $response['searchTerms'] = $terms;

    // Create a new array of bins which will hold the relevance score for each page.
    $bins = new RelevanceBin();

    // Obtain results for each term in the search phrase
    foreach ($terms as $term) {
        // Search through keywords for all pages which contain a matching keyword.
        $sql = 'SELECT page_id, dupe_count FROM keywords WHERE keyword = ? ORDER BY page_id DESC';
        $statement = $pdo->prepare($sql);
        $statement->execute([$term]);
        $results = $statement->fetchAll(); // Returns an array of indexed and associative results.

        $max = 0;
        // Add up the relevance score for each page based on keyword occurances on the page.
        foreach ($results as $result) {
            $bins->add_bin($result['page_id'], $result['dupe_count']);
            if ($result['dupe_count'] > $max) {
                $max = $result['dupe_count'];
            }
        }

        // Add the max dupe_count for this term to the max array in the RelevanceBin instance.
        // We will use this to calculate relevance for each page later.
        $bins->add_max($max);
    }

    // Sort the pages by their relevance score
    //$bins = $bins->get_bins();
    $relevance_arr = $bins->create_relevance_arr();
    arsort($relevance_arr); // Sorted in descending order (most relevant to least relevant).
    //$relevant_pages = $bins;

    // Put all array keys (aka page_id's) into a separate array.
    $page_ids = array_keys($relevance_arr);

    // Grab pages from the database in the order of page relevance.
    $search_results = [];
    foreach ($page_ids as $page_id) {
        $sql = 'SELECT path, title, description FROM pages WHERE page_id = ' . $page_id;
        $statement = $pdo->prepare($sql);
        $statement->execute();
        $results = $statement->fetch(); // Returns an array of indexed and associative results. Indexed is preferred.

        $search_results[] = new Result($url . $results[0], $results[1], $results[2]);
    }

    $response['relevance_arr'] = $relevance_arr;
    $response['totalResults'] = count($search_results);
    $response['totalPages'] = ceil(count($search_results) / 10);
    $result_pages = array_chunk($search_results, 10);
    $response['results'] = $result_pages[$page_to_return];
    echo json_encode($response);
} 
catch (Exception $e) {
    // One of our database queries have failed.
    // Print out the error message.
    //echo $e->getMessage();
    $response['db_error'] = $e->getMessage();
    // Rollback the transaction.
    if (isset($pdo)) {
        $pdo->rollBack();
    }
}

// Monitor program performance using this timer
$end = round(microtime(true) * 1000);
$response['time_taken'] = $end - $begin;

// Send a response back to the client.
//echo json_encode($response);

// Input: String
// Output: String containing only letters and numbers (ASCII)
// Options: [ 'symbols' => bool, 'lower' => bool, 'upper' => bool]. True indicates to remove.
// Removes unknown and unwanted symbols from a given string.
function sanitize($str, $options = ['symbols' => false, 'lower' => false, 'upper' => false]) {
    $symbols_reg = '\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E';
    $lower_reg = '\x61-\x7A';
    $upper_reg = '\x41-\x5A';
    $regexp = '/[\x00-\x1F';
  
    if ($options['symbols']) {
      $regexp .= $symbols_reg;
    }
    if ($options['lower']) {
      $regexp .= $lower_reg;
    }
    if ($options['upper']) {
      $regexp .= $upper_reg;
    }
  
    $regexp .= '\x80-\xFF]/';
  
    $str = strtolower($str);
    return preg_replace($regexp, '', $str); // Remove unwanted characters based on the values in the options array.
}

// Input: total_placeholders; The amount of '?' to go into each string like (?, ?, ..., ?)
//        total_values; The amount of (?, ?, ..., ?) strings to create for the PDO/SQL request.
// Output: A placeholder string that is used within sql queries for PDO.
// Generate a valid PDO placeholder string for some sql query.
function create_pdo_placeholder_str($total_placeholders, $total_values) {
    // Generate the PDO placeholder string to be repeated $total_values times.
    $placeholder_unit = '(';
    for ($i = 0; $i < $total_placeholders; $i++) {
      if ($i + 1 === $total_placeholders) {
        $placeholder_unit .= '?)';
      }
      else {
        $placeholder_unit .= '?, ';
      }
    }
  
    // Repeat the $placeholder_value a total of $total_values times.
    // This forms a correct PDO string which is placed after VALUES inside an sql statement.
    $pdo_str = '';
    for ($i = 0; $i < $total_values; $i++) {
      if ($i + 1 === $total_values) {
        $pdo_str .= $placeholder_unit;
      }
      else {
        $pdo_str .= $placeholder_unit . ',';
      }
    }
  
    return $pdo_str;
}

// Input: arr is the array of words
//        l is low index
//        h is high index
//        key is the word we're searching for
// Output: Index of the located word in the given array (arr)
// For checking spelling and search terms
function binarySearchWord($arr, $l, $h, $key) { 
    while ($h >= $l) {
        $mid = ceil($l + ($h - $l) / 2); 

        // If the element is present at the middle itself 
        if ($arr[$mid]['word'] == $key) {
            return floor($mid); 
        }

        // If element is smaller than mid, then 
        // it can only be present in left subarray 
        if ($arr[$mid]['word'] > $key) {
            $h = $mid - 1;
        }
        else {
            // Else the element can only be present in right subarray 
            $l = $mid + 1;
        }
    }

    // We reach here when element is not present in array 
    return -1;
} 

// Get rid of a suffix at the end of a word.
// Ex. Get rid of the 's' at the end of services.
function removeSuffix($str) {

}