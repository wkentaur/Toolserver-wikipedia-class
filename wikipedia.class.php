<?PHP
//
//class Wikipedia
//
//class to access wikipedia database on toolserver


// CONSTANTS
//wikipedia namespaces
define('WP_ARTICLE_NS', 0);
define('WP_TEMPLATE_NS', 4);

//toolserver wikipedia db name postfix
define('TS_DB_NAME_POSTFIX', 'wiki_p');

//toolserver wikipedia host name postfix
define('TS_DB_HOST_POSTFIX', 'wiki-p.db.toolserver.org');


class Wikipedia {
    // define which Wikipedia to use, lang code 'en', etc.
	private $wiki_langcode = '';
    // MySql host_link
    private $mysql_host_link = null;
    // MySql db
    // FIXME unneeded, really!!!!!!
    private $wiki_db = null;
    // page_title of the last page queried
    private $last_page_title;
    // page_id of the last page queried
    private $last_page_id;
    // status message of the last page queried
    private $last_page_status;
    // follow_redirect state of the last page queried
    private $last_follow_redirect;

    function __construct($in_langcode) {
        if ($in_langcode) {
            $this->select_wiki_db($in_langcode);
        } else {
            die('langcode not set!');
        }
    } //func

    private function select_wiki_db($in_langcode) {
    
        //connect to Wikipedia db in MySQL
        $toolserver_mycnf = parse_ini_file("/home/".get_current_user()."/.my.cnf");
        $w_db_host = $in_langcode . TS_DB_HOST_POSTFIX;
        $w_db_name = $in_langcode . TS_DB_NAME_POSTFIX;
        $host_link = mysql_connect($w_db_host, $toolserver_mycnf['user'], $toolserver_mycnf['password']); 
        if (!$host_link) {
            die('Could not connect: ' . mysql_error());
        }
        $this->mysql_host_link = $host_link;
        $db_selected = mysql_select_db($w_db_name, $this->mysql_host_link);
        unset($toolserver_mycnf);
        
        if ($db_selected) {
            $this->wiki_db = $db_selected;
            $this->wiki_langcode = $in_langcode;
        } else {
            die('Couldnt select database!');
        }

    } //func select_wiki_db
   
    // return true if page exists on wiki; also true if its just redirect page
    function is_page($in_page_name, $in_page_namespace = WP_ARTICLE_NS) { 
    
        $ret_is_article = false;        
        
        if ($in_page_name) {
            $ret_arr = $this->get_page_id_fulldesc($in_page_name, $in_page_namespace);
            $ret_status = $ret_arr[0];
            if (strcmp ($ret_status, 'OK') == 0 
                   OR strcmp ($ret_status, 'REDIRECT') == 0) {
                $ret_is_article = true;
            }
        } else {
            die('page name not set!');
        }
        
        return $ret_is_article;
    } //func

    //get Wikipedia page: page_id and return info about page 
    private function get_page_id_fulldesc($in_page_title, $in_page_namespace = WP_ARTICLE_NS, $in_follow_redirect = false) {

        if ( (strcmp($in_page_title, $this->last_page_title) == 0) AND ($in_follow_redirect == $this->last_follow_redirect) ) {  //no need to make new query
            $out_status = $this->last_page_status;
            $out_wiki_page_id = $this->last_page_id;
        } else {
    
            $out_status = '';
            $out_wiki_page_id = '';
            $this->last_page_title = '';
            $this->last_page_id = '';
            $this->last_page_status = '';
   
            $page_title = $this->add_underscores($in_page_title);
            //FIXME add redirect-pages support
            $art_sql = sprintf("SELECT page_id, page_is_redirect FROM page
              WHERE page_namespace = '%s' AND page_title = '%s'",
              $in_page_namespace,
              mysql_real_escape_string($page_title));
              
            $result = mysql_query($art_sql, $this->mysql_host_link);
            if (!$result) {
                die('Invalid query: ' . mysql_error());
            }

            $this->last_page_title = $in_page_title;
            if (mysql_num_rows($result)) {
                while ($art_row = mysql_fetch_assoc($result)) {
                    if ( $art_row['page_is_redirect'] ) {
                        if ($in_follow_redirect) {
                            //FIXME hence, function get_page_id_fulldesc() should also return page namespace
                            //FIXME ... and last ns should also be added as class variable
                            $redir_arr = $this->get_redir_page_ns_title($art_row['page_id']);
                            $redir_ns = $redir_arr[0];
                            $redir_title = $redir_arr[1];
                            $tmp_out_arr = $this->get_page_id_fulldesc($redir_title, $redir_ns);
                            $out_status = $tmp_out_arr[0];
                            $out_wiki_page_id = $tmp_out_arr[1];
                        } else {
                            $out_status = 'REDIRECT';
                            $out_wiki_page_id = $art_row['page_id'];
                        }
                    } else {
                        $out_status = 'OK';
                        $out_wiki_page_id = $art_row['page_id'];
                    } //if-else
                    $this->last_page_id = $out_wiki_page_id;
                } //while
            } else {
                $out_status = 'NOT_FOUND';
            }
            $this->last_page_status = $out_status;
            $this->last_follow_redirect = $in_follow_redirect;
       
            // Free the resources associated with the result set
            // This is done automatically at the end of the script
            mysql_free_result($result);
        } //if-else
    
        return array($out_status, $out_wiki_page_id);
    } //func


    //get Wikipedia page: page_id 
    private function get_page_id($in_page_title, $in_page_namespace = WP_ARTICLE_NS, $in_follow_redirect = false) {
        $ret_page_id = '';
        
        if ($in_page_title) {
            $ret_arr = $this->get_page_id_fulldesc($in_page_title, $in_page_namespace, $in_follow_redirect);
            if ($ret_arr[1]) {
                $ret_page_id = $ret_arr[1];
            }
        } else {
            die('page name not set!');
        }
         
        return $ret_page_id;
    } //func

    //get redirected page title and namespace    
    function get_redir_page_ns_title($in_page_id) {
        $ret_page_ns = '';
        $ret_page_title = '';
        
        if ($in_page_id) {
            $art_sql = sprintf("SELECT rd_namespace, rd_title FROM redirect
                WHERE rd_from = '%s'",
                $in_page_id);

            $result = mysql_query($art_sql, $this->mysql_host_link);
            if (!$result) {
                die('Invalid query: ' . mysql_error());
            }

            if (mysql_num_rows($result)) {
                while ($art_row = mysql_fetch_assoc($result)) {
                    $ret_page_ns = $art_row['rd_namespace'];
                    $ret_page_title = $art_row['rd_title'];
                }
            }
        }
        
        return array($ret_page_ns, $ret_page_title);
    
    } //func
    
    
    //check if article is disambiguation page   
    function is_disambig_page($in_page_title, $in_disambig_template) {
        $ret_is_disambig_page = false;
        
        if ($in_page_title AND $in_disambig_template) {
            $page_id = $this->get_page_id($in_page_title);

            if ($page_id) {
                $art_sql = sprintf("SELECT pl_from FROM pagelinks
                WHERE pl_from = '%s' AND pl_title = '%s' AND pl_namespace = '%s'
                LIMIT 1",
                $page_id,
                mysql_real_escape_string( $this->add_underscores($in_disambig_template) ),
                WP_TEMPLATE_NS);

                $result = mysql_query($art_sql, $this->mysql_host_link);
                if (!$result) {
                    die('Invalid query: ' . mysql_error());
                }

                if (mysql_num_rows($result)) {
                    $ret_is_disambig_page = true;
                }        
            }
        }
        
        return $ret_is_disambig_page;
    } //func

    //check if there's wikilink to a page
    function is_linked($in_page_title, $in_page_namespace = WP_ARTICLE_NS) {
        $ret_is_linked = false;
    
        if ($in_page_title) {
            $art_sql = sprintf("SELECT pl_title FROM pagelinks
              WHERE pl_title = '%s' AND pl_namespace = '%s'
              LIMIT 1",
              mysql_real_escape_string( $this->add_underscores($in_page_title) ),
              $in_page_namespace);

            $result = mysql_query($art_sql, $this->mysql_host_link);
            if (!$result) {
                die('Invalid query: ' . mysql_error());
            }

            if (mysql_num_rows($result)) {
                $ret_is_linked = true;
            }                    
        } else {
            die('page_name not given!');
        }
        
        return $ret_is_linked;
    }

    //check if page is in category, follows page redirect
    function is_in_category($in_page_title, $in_category, $in_page_namespace = WP_ARTICLE_NS) {
        $ret_is_in_category = false;
        $follow_redirect = true; //should follow page redirects
        
        if ($in_page_title AND $in_category) {
            $page_id = $this->get_page_id($in_page_title, $in_page_namespace, $follow_redirect);
            $category = $this->add_underscores($in_category);

            if ($page_id) {
                $art_sql = sprintf("SELECT cl_from FROM categorylinks
                WHERE cl_from = '%s' AND cl_to = '%s'",
                $page_id,
                mysql_real_escape_string($category) );

                $result = mysql_query($art_sql, $this->mysql_host_link);
                if (!$result) {
                    die('Invalid query: ' . mysql_error());
                }

                if (mysql_num_rows($result)) {
                    $ret_is_in_category = true;
                }        
            }
        }
        
        return $ret_is_in_category;
    } //func
    
    
    //check if page is in category like name, follows page redirect
    function is_in_category_like($in_page_title, $in_category_like, $in_page_namespace = WP_ARTICLE_NS) {
        $ret_is_in_category = false;
        $follow_redirect = true; //should follow page redirects
        
        if ($in_page_title AND $in_category_like) {
            $page_id = $this->get_page_id($in_page_title, $in_page_namespace, $follow_redirect);
            $category_like = $this->add_underscores($in_category_like);

            if ($page_id) {
                $art_sql = sprintf("SELECT cl_from FROM categorylinks
                WHERE cl_from = '%s' AND cl_to LIKE '%s'", 
                $page_id,
                mysql_real_escape_string($category_like) );

                $result = mysql_query($art_sql, $this->mysql_host_link);
                if (!$result) {
                    die('Invalid query: ' . mysql_error());
                }

                if (mysql_num_rows($result)) {
                    $ret_is_in_category = true;
                }        
            }
        }
        
        return $ret_is_in_category;
    } //func

    
    function add_underscores($in_string) {
        return str_replace(' ', '_', $in_string);
    }
    
    
    function __destruct() {
        //close mysql connection
        if ($this->mysql_host_link) {
            mysql_close($this->mysql_host_link);
        }
    } //func
    
} //class

?>