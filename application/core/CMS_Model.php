<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Base Model of No-CMS
 *
 * @author gofrendi
 */
class CMS_Model extends CI_Model
{
    public $PRIV_EVERYONE             = 1;
    public $PRIV_NOT_AUTHENTICATED    = 2;
    public $PRIV_AUTHENTICATED        = 3;
    public $PRIV_AUTHORIZED           = 4;
    public $PRIV_EXCLUSIVE_AUTHORIZED = 5;

    protected static $__cms_model_properties;

    public function cms_list_fields($table_name){
        if($this->db instanceof CI_DB_pdo_sqlite_driver){
            $result = $this->db->get($table_name);
            $row_array = $result->row_array();
            $field_list = array();
            foreach($row_array as $key=>$value){
                $field_list[] = $key;
            }
            return $field_list;
        }else{
            return $this->db->list_fields($table_name);   
        }
    }

    public function __construct()
    {
        parent::__construct();

        // PHP 5.3 ask for timezone, and throw a warning whenever it is not available
        // so, just give this one :)
        $timezone = @date_default_timezone_get();
        if (!isset($timezone) || $timezone == '') {
            $timezone = @ini_get('date.timezone');
        }
        if (!isset($timezone) || $timezone == '') {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);

        // load helpers and libraries
        $this->load->helper('url');
        $this->load->helper('html');
        $this->load->helper('form');
        $this->load->helper('string');
        $this->load->library('user_agent');
        $this->load->driver('session');
        $this->load->helper('cms_helper');
        $this->load->library('form_validation');
        $this->load->database();

        // accessing file is faster than accessing database
        // but I think accessing variable is faster than both of them
        
        if(self::$__cms_model_properties == NULL){
            self::$__cms_model_properties = array();
        }
        $default_properties = array(
                'session' => array(),
                'language_dictionary' => array(),
                'config' => array(),
                'module_name' => array(),
                'module_path' => array(),
                'module_version' => array(),
                'navigation' => array(),        // cache raw query
                'quicklink' => array(),         // cache already built quicklink
                'widget' => array(),            // cache raw query
                'super_admin' => NULL,
                'properties' => array(),
                'is_config_cached' => FALSE,
                'is_module_name_cached' => FALSE,
                'is_module_path_cached' => FALSE,
                'is_module_version_cached' => FALSE,
                'is_user_last_active_extended' => FALSE,
                'is_navigation_cached' => FALSE,
                'is_quicklink_cached' => FALSE,
                'is_widget_cached' => FALSE,
            );
        foreach($default_properties as $key=>$val){
            if(!array_key_exists($key, self::$__cms_model_properties)){
                self::$__cms_model_properties[$key] = $val;
            }
        }

        if(self::$__cms_model_properties['super_admin'] === NULL){
            $query = $this->db->select('user_name, real_name')
                    ->from(cms_table_name('main_user'))
                    ->where('user_id', 1)
                    ->get();
            $super_admin = $query->row();
            self::$__cms_model_properties['super_admin'] = $super_admin;
        }

        // kcfinder
        include(APPPATH.'config/main/cms_config.php');
        if(array_key_exists('__cms_chipper', $config)){
            $chipper = $config['__cms_chipper'];
        }else{
            $chipper = 'Love Song Storm Gravity Tonight End of Sorrow Rosier';
        }

        setcookie(cms_encode('__cms_base_url', $chipper),   cms_encode(base_url(), $chipper));
        setcookie(cms_encode('__cms_subsite', $chipper),    cms_encode(CMS_SUBSITE, $chipper));
        setcookie(cms_encode('__cms_user_id', $chipper),    cms_encode($this->cms_user_id()));

        // extend user last active status
        $this->__cms_extend_user_last_active($this->cms_user_id());
    }

    public function __destruct(){
        @$this->session->unset_userdata('cms_dynamic_widget');
    }

    public function cms_get_super_admin(){
        return self::$__cms_model_properties['super_admin'];
    }

    /**
     * @author goFrendiAsgard
     * @param  string $table_name
     * @return string
     * @desc   return good table name
     */
    public function cms_complete_table_name($table_name){
        if(!isset($_SESSION)){
            session_start();
        }
        $module_path = $this->cms_module_path();
        if($module_path == 'main' or $module_path == ''){
            return cms_table_name($table_name);
        }else{
            if(file_exists(FCPATH.'modules/'.$module_path.'/cms_helper.php')){ 
                $this->load->helper($module_path.'/cms');
                if(function_exists('cms_complete_table_name')){
                    return cms_complete_table_name($table_name);
                }
            }
            return cms_module_table_name($module_path, $table_name);
        }
    }

    /**
     * @author goFrendiAsgard
     * @param  string $navigation_name
     * @return string
     * @desc   return good table name
     */
    public function cms_complete_navigation_name($navigation_name){
        $module_path = $this->cms_module_path();
        if($module_path == 'main' or $module_path == ''){
            return $navigation_name;
        }else{
            return cms_module_navigation_name($module_path, $navigation_name);
        }
    }

    /**
     * @author goFrendiAsgard
     * @param  string $key
     * @param  mixed $value
     * @return mixed
     * @desc   if value specified, this will set CI_Session["key"], else it will return CI_session["key"]
     */
    public function cms_ci_session($key, $value = NULL)
    {
        if ($value !== NULL) {
            $this->session->set_userdata($key, $value);
            self::$__cms_model_properties['session'][$key] = $value;
        }
        // add to __cms_model_properties if not exists
        if (!array_key_exists($key, self::$__cms_model_properties['session'])) {
            self::$__cms_model_properties['session'][$key] = $this->session->userdata($key);
        }
        return self::$__cms_model_properties['session'][$key];
    }

    /**
     * @author goFrendiAsgard
     * @param  string $key
     * @desc   unset CI_session["key"]
     */
    public function cms_unset_ci_session($key)
    {
        $this->session->unset_userdata($key);
        unset(self::$__cms_model_properties['session'][$key]);
    }

    public function cms_cached_property($key, $value = NULL)
    {
        if ($value !== NULL) {
            self::$__cms_model_properties['properties'][$key] = $value;
        }
        // add to __cms_model_properties if not exists
        if (!array_key_exists($key, self::$__cms_model_properties['properties'])) {
            self::$__cms_model_properties['properties'][$key] = NULL;
        }
        return self::$__cms_model_properties['properties'][$key];
    }

    public function cms_is_property_cached($key){
        return array_key_exists($key, self::$__cms_model_properties['properties']);    
    }

    /** 
     * @author goFrendiAsgard
     * @desc   get default_controller
     */
    public function cms_get_default_controller(){
        if(CMS_SUBSITE == ''){
            include(APPPATH.'config/main/routes.php');
        }else{
            include(APPPATH.'config/site-'.CMS_SUBSITE.'/routes.php');
        }
        return $route['default_controller'];
    }

    /**
     * @author goFrendiAsgard
     * @param  string $value
     * @desc   set default_controller to value
     */
    public function cms_set_default_controller($value){
        $pattern = array();
        $pattern[] = '/(\$route\[(\'|")default_controller(\'|")\] *= *")(.*?)(";)/si';
        $pattern[] = "/(".'\$'."route\[('|\")default_controller('|\")\] *= *')(.*?)(';)/si";
        if(CMS_SUBSITE == ''){
            $file_name = APPPATH.'config/main/routes.php';
        }else{
            $file_name = APPPATH.'config/site-'.CMS_SUBSITE.'/routes.php';
        }
        $str = file_get_contents($file_name);
        $replacement = '${1}'.addslashes($value).'${5}';
        $found = FALSE;
        foreach($pattern as $single_pattern){
            if(preg_match($single_pattern,$str)){
                $found = TRUE;
                break;
            }
        }
        if(!$found){
            $str .= PHP_EOL.'$route[\'default_controller\'] = \''.addslashes($value).'\';';
        }
        else{
            $str = preg_replace($pattern, $replacement, $str);
        }
        @chmod($file_name,0777);
        if(strpos($str, '<?php') !== FALSE && strpos($str, '$route') !== FALSE){
            @file_put_contents($file_name, $str);
            @chmod($file_name,0555);
        }
    }

    /**
     * @author goFrendiAsgard
     * @param  string $hostname
     * @param  int    $port
     * @desc   is it able to go to some site?
     */
    public function cms_is_connect($hostname=NULL, $port=80){
        if($this->cms_get_config('cms_internet_connectivity') === 'ONLINE'){
            return TRUE;
        }else if($this->cms_get_config('cms_internet_connectivity') === 'OFFLINE'){
            return FALSE;
        }
        $hostname = $hostname === NULL? 'google.com' : $hostname;
        // return from session if we have look for it before
        if($this->cms_ci_session('cms_connect_'.$hostname)){
            // if last connect attempt is more than 60 seconds, try again
            if(microtime(true) - $this->cms_ci_session('cms_last_contact_'.$hostname) > 60){
                $this->cms_unset_ci_session('cms_connect_'.$hostname);
                return $this->cms_is_connect($hostname, $port);
            }
            return $this->cms_ci_session('cms_connect_'.$hostname);
        }
        // we never look for it before, now look for it and save on session
        $connected = @fsockopen($hostname, $port);
        if ($connected){
            $is_conn = true; //action when connected
            fclose($connected);
        }else{
            $is_conn = false; //action in connection failure
        }
        // get hostname
        $host_name = explode(':',$_SERVER['HTTP_HOST']);
        $host_name = $host_name[0];
        // if hostname is not localhost, change the UNKNOWN cms_internet_connectivity into ONLINE or OFFLINE
        if($host_name != 'localhost' && $host_name != '127.0.0.1'){
            if($is_conn){
                $this->cms_set_config('cms_internet_connectivity', 'ONLINE');
            }else{
                $this->cms_set_config('cms_internet_connectivity', 'OFFLINE');
            }
        }
        $this->cms_ci_session('cms_connect_'.$hostname, $is_conn);
        $this->cms_ci_session('cms_last_contact_'.$hostname, microtime(true));
        return $is_conn;
    }

    /**
     * @author goFrendiAsgard
     * @param  string $user_name
     * @return mixed
     * @desc   set or get CI_Session["cms_user_name"]
     */
    public function cms_user_name($user_name = NULL)
    {
        return $this->cms_ci_session('cms_user_name', $user_name);
    }

    /**
     * @author goFrendiAsgard
     * @param  string $real_name
     * @return mixed
     * @desc   set or get CI_Session["cms_user_real_name"]
     */
    public function cms_user_real_name($real_name = NULL)
    {
        return $this->cms_ci_session('cms_user_real_name', $real_name);
    }

    /**
     * @author goFrendiAsgard
     * @param  string $email
     * @return mixed
     * @desc   set or get CI_Session["cms_user_email"]
     */
    public function cms_user_email($email = NULL)
    {
        return $this->cms_ci_session('cms_user_email', $email);
    }

    /**
     * @author goFrendiAsgard
     * @param  int $user_id
     * @desc   set or get CI_Session["cms_user_id"]
     */
    public function cms_user_id($user_id = NULL)
    {
        return $this->cms_ci_session('cms_user_id', $user_id);
    }

    /**
     * @author goFrendiAsgard
     * @return array
     * @desc   get group list of current user
     */
    public function cms_user_group(){
        $query = $this->db->select('group_name')
            ->from(cms_table_name('main_group'))
            ->join(cms_table_name('main_group_user'), cms_table_name('main_group_user').'.group_id = '.cms_table_name('main_group').'.group_id')
            ->where(cms_table_name('main_group_user').'.user_id', $this->cms_user_id())
            ->get();
        $group_name = array();
        foreach($query->result() as $row){
            $group_name[] = $row->group_name;
        }
        return $group_name;
    }

    /**
     * @author goFrendiAsgard
     * @return array
     * @desc   get group list of current user
     */
    public function cms_user_group_id(){
        $query = $this->db->select('group_id')
            ->from(cms_table_name('main_group_user'))
            ->where(cms_table_name('main_group_user').'.user_id', $this->cms_user_id())
            ->get();
        $group_id = array();
        foreach($query->result() as $row){
            $group_id[] = $row->group_id;
        }
        return $group_id;
    }

    /**
     * @author goFrendiAsgard
     * @return boolean
     * @desc   TRUE if current user is super admin, FALSE otherwise
     */
    public function cms_user_is_super_admin(){
        if($this->cms_user_id()==1){
            return TRUE;
        }
        $query = $this->db->select('group_name')
            ->from(cms_table_name('main_group'))
            ->join(cms_table_name('main_group_user'), cms_table_name('main_group_user').'.group_id = '.cms_table_name('main_group').'.group_id')
            ->where(cms_table_name('main_group_user').'.user_id', $this->cms_user_id())
            ->where(cms_table_name('main_group').'.group_id', 1)
            ->get();
        return $query->num_rows()>0;
    }

    /**
     * @author  goFrendiAsgard
     * @param   int parent_id
     * @param   int max_menu_depth
     * @desc    return navigation child if parent_id specified, else it will return root navigation
     *           the max depth of menu is depended on max_menud_depth
     */
    public function cms_navigations($parent_id = NULL, $max_menu_depth = NULL)
    {

        $user_name  = $this->cms_user_name();
        $user_id    = $this->cms_user_id();
        $user_id    = $user_id == ''?0:$user_id;
        $not_login  = !$user_name ? "(1=1)" : "(1=2)";
        $login      = $user_name ? "(1=1)" : "(1=2)";
        $super_user = ($user_id == 1 || in_array(1,$this->cms_user_group_id())) ? "(1=1)" : "(1=2)";

        //get max_menu_depth from configuration
        if (!isset($max_menu_depth)) {
            $max_menu_depth = $this->cms_get_config('max_menu_depth');
            if(!isset($max_menu_depth)){
                $max_menu_depth = 10;
                $this->cms_set_config('max_menu_depth', $max_menu_depth);
            }
        }

        if ($max_menu_depth > 0) {
            $max_menu_depth--;
        } else {
            return array();
        }

        // $where_is_root = !isset($parent_id) ? "(parent_id IS NULL)" : "parent_id = '" . addslashes($parent_id) . "'";
        if(!self::$__cms_model_properties['is_navigation_cached']){
            $query = $this->db->query("SELECT navigation_id, navigation_name, bootstrap_glyph, is_static, title, description, url, notif_url, active, parent_id,
                        (
                            (authorization_id = 1) OR
                            (authorization_id = 2 AND $not_login) OR
                            (authorization_id = 3 AND $login) OR
                            (
                                (authorization_id = 4 AND $login) AND
                                (
                                    (SELECT COUNT(*) FROM ".cms_table_name('main_group_user')." AS gu WHERE gu.group_id=1 AND gu.user_id =" . addslashes($user_id) . ")>0
                                        OR $super_user OR
                                    (SELECT COUNT(*) FROM ".cms_table_name('main_group_navigation')." AS gn
                                        WHERE
                                            gn.navigation_id=n.navigation_id AND
                                            gn.group_id IN
                                                (SELECT group_id FROM ".cms_table_name('main_group_user')." WHERE user_id = " . addslashes($user_id) . ")
                                    )>0
                                )
                            ) OR
                            (
                                (authorization_id = 5 AND $login) AND
                                (
                                    (SELECT COUNT(*) FROM ".cms_table_name('main_group_navigation')." AS gn
                                        WHERE
                                            gn.navigation_id=n.navigation_id AND
                                            gn.group_id IN
                                                (SELECT group_id FROM ".cms_table_name('main_group_user')." WHERE user_id = " . addslashes($user_id) . ")
                                    )>0
                                )
                            )
                        ) AS allowed
                    FROM ".cms_table_name('main_navigation')." AS n ORDER BY n.".$this->db->protect_identifiers('index'));
            
            self::$__cms_model_properties['is_navigation_cached'] = TRUE;
            self::$__cms_model_properties['navigation'] = $query->result();
        }
        $result = array();
        foreach (self::$__cms_model_properties['navigation'] as $row) {
            if($parent_id === NULL){
                if($row->parent_id != NULL){
                    continue;
                }
            }else{
                if($row->parent_id != $parent_id){
                    continue;
                }
            }
            $children              = $this->cms_navigations($row->navigation_id, $max_menu_depth);
            $have_allowed_children = false;
            foreach ($children as $child) {
                if ($child["allowed"] && $child["active"]) {
                    $have_allowed_children = true;
                    break;
                }
            }
            if ((!isset($row->url) || $row->url == '' || strpos(strtoupper($row->url), 'HTTP://') !== FALSE  || strpos(strtoupper($row->url), 'HTTPS://') !== FALSE ) && $row->is_static == 1) {
                $url = site_url('main/static_page/' . $row->navigation_name);
            } else {
                if (strpos(strtoupper($row->url), 'HTTP://') !== FALSE || strpos(strtoupper($row->url), 'HTTPS://') !== FALSE) {
                    $url = $row->url;
                } else {
                    $url = site_url($row->url);
                }
            }
            if(trim($row->notif_url) == ''){
                $notif_url = '';
            } else if (strpos(strtoupper($row->notif_url), 'HTTP://') !== FALSE || strpos(strtoupper($row->notif_url), 'HTTPS://') !== FALSE) {
                $notif_url = $row->notif_url;
            } else {
                $notif_url = site_url($row->notif_url);
            }
            $result[] = array(
                "navigation_id" => $row->navigation_id,
                "navigation_name" => $row->navigation_name,
                "bootstrap_glyph" => $row->bootstrap_glyph,
                "title" => $this->cms_lang($row->title),
                "description" => $this->cms_lang($row->description),
                "url" => $url,
                "notif_url" => $notif_url,
                "is_static" => $row->is_static,
                "active" => $row->active,
                "child" => $children,
                "allowed" => $row->allowed,
                "have_allowed_children" => $have_allowed_children
            );
        }

        return $result;
    }

    /**
     * @author goFrendiAsgard
     * @return mixed
     * @desc   return quick links
     */
    public function cms_quicklinks()
    {
        if(self::$__cms_model_properties['is_quicklink_cached']){
            return self::$__cms_model_properties['quicklink'];
        }

        $user_name  = $this->cms_user_name();
        $user_id    = $this->cms_user_id();
        $user_id    = $user_id == ''?0:$user_id;
        $not_login  = !$user_name ? "(1=1)" : "(1=2)";
        $login      = $user_name ? "(1=1)" : "(1=2)";
        $super_user = ($user_id == 1 || in_array(1,$this->cms_user_group_id())) ? "(1=1)" : "(1=2)";

        $query  = $this->db->query("
                        SELECT q.navigation_id, navigation_name, bootstrap_glyph, is_static, title, description, url, notif_url, active,
                        (
                            (authorization_id = 1) OR
                            (authorization_id = 2 AND $not_login) OR
                            (authorization_id = 3 AND $login) OR
                            (
                                (authorization_id = 4 AND $login) AND
                                (
                                    (SELECT COUNT(*) FROM ".cms_table_name('main_group_user')." AS gu WHERE gu.group_id=1 AND gu.user_id =" . addslashes($user_id) . ")>0
                                        OR $super_user OR
                                    (SELECT COUNT(*) FROM ".cms_table_name('main_group_navigation')." AS gn
                                        WHERE
                                            gn.navigation_id=n.navigation_id AND
                                            gn.group_id IN
                                                (SELECT group_id FROM ".cms_table_name('main_group_user')." WHERE user_id = " . addslashes($user_id) . ")
                                    )>0
                                )
                            ) OR
                            (
                                (authorization_id = 5 AND $login) AND
                                (
                                    (SELECT COUNT(*) FROM ".cms_table_name('main_group_navigation')." AS gn
                                        WHERE
                                            gn.navigation_id=n.navigation_id AND
                                            gn.group_id IN
                                                (SELECT group_id FROM ".cms_table_name('main_group_user')." WHERE user_id = " . addslashes($user_id) . ")
                                    )>0
                                )
                            )
                        ) as allowed
                        FROM
                            ".cms_table_name('main_navigation')." AS n,
                            ".cms_table_name('main_quicklink')." AS q
                        WHERE
                            (
                                q.navigation_id = n.navigation_id
                            )
                            ORDER BY q.".$this->db->protect_identifiers('index'));
        $result = array();
        foreach ($query->result() as $row) {
            $children   = $this->cms_navigations($row->navigation_id);
            $have_allowed_children = false;
            foreach ($children as $child) {
                if ($child["allowed"] && $child["active"]) {
                    $have_allowed_children = TRUE;
                    break;
                }
            }
            if ((!isset($row->url) || $row->url == '') && $row->is_static == 1) {
                $url = 'main/static_page/' . $row->navigation_name;
            } else {
                if (strpos(strtoupper($row->url), 'HTTP://') !== FALSE || strpos(strtoupper($row->url), 'HTTPS://') !== FALSE) {
                    $url = $row->url;
                } else {
                    $url = site_url($row->url);
                }
            }
            if(trim($row->notif_url) == ''){
                $notif_url = '';
            } else if (strpos(strtoupper($row->notif_url), 'HTTP://') !== FALSE || strpos(strtoupper($row->notif_url), 'HTTPS://') !== FALSE) {
                $notif_url = $row->notif_url;
            } else {
                $notif_url = site_url($row->notif_url);
            }
            $result[] = array(
                "navigation_id" => $row->navigation_id,
                "navigation_name" => $row->navigation_name,
                "bootstrap_glyph" => $row->bootstrap_glyph,
                "allowed" => $row->allowed,
                "have_allowed_children"=>$have_allowed_children,
                "title" => $this->cms_lang($row->title),
                "description" => $row->description,
                "url" => $url,
                "notif_url" => $notif_url,
                "is_static" => $row->is_static,
                "child" => $children,
                "active" => $row->active,
            );
        }

        self::$__cms_model_properties['quicklink'] = $result;
        self::$__cms_model_properties['is_quicklink_cached'] = TRUE;

        return $result;
    }

    /**
     * @author  goFrendiAsgard
     * @param   slug
     * @param   widget_name
     * @return  mixed
     * @desc    return widgets
     */
    public function cms_widgets($slug = NULL, $widget_name=NULL)
    {
        // get user_name, user_id, etc
        $user_name  = $this->cms_user_name();
        $user_id    = $this->cms_user_id();
        $user_id    = $user_id == ''?0:$user_id;
        $not_login  = !$user_name ? "(1=1)" : "(1=2)";
        $login      = $user_name ? "(1=1)" : "(1=2)";
        $super_user = ($user_id == 1 || in_array(1,$this->cms_user_group_id())) ? "(1=1)" : "(1=2)";
        
        /*
        $slug_where = isset($slug)?
            "(((slug LIKE '".addslashes($slug)."') OR (slug LIKE '%".addslashes($slug)."%')) AND active=1)" :
            "1=1";
        $widget_name_where = isset($widget_name)? "widget_name LIKE '".addslashes($widget_name)."'" : "1=1";
        */

        if(!self::$__cms_model_properties['is_widget_cached']){
            $SQL = "SELECT
                        widget_id, widget_name, is_static, title,
                        description, url, slug, static_content, active
                    FROM ".cms_table_name('main_widget')." AS w WHERE
                        (
                            (authorization_id = 1) OR
                            (authorization_id = 2 AND $not_login) OR
                            (authorization_id = 3 AND $login) OR
                            (
                                (authorization_id = 4 AND $login) AND
                                (
                                    (SELECT COUNT(*) FROM ".cms_table_name('main_group_user')." AS gu WHERE gu.group_id=1 AND gu.user_id ='" . addslashes($user_id) . "')>0
                                        OR $super_user OR
                                    (SELECT COUNT(*) FROM ".cms_table_name('main_group_widget')." AS gw
                                        WHERE
                                            gw.widget_id=w.widget_id AND
                                            gw.group_id IN
                                                (SELECT group_id FROM ".cms_table_name('main_group_user')." WHERE user_id = " . addslashes($user_id) . ")
                                    )>0
                                )
                            ) OR
                            (
                                (authorization_id = 5 AND $login) AND
                                (
                                    (SELECT COUNT(*) FROM ".cms_table_name('main_group_widget')." AS gw
                                        WHERE
                                            gw.widget_id=w.widget_id AND
                                            gw.group_id IN
                                                (SELECT group_id FROM ".cms_table_name('main_group_user')." WHERE user_id = " . addslashes($user_id) . ")
                                    )>0
                                )
                            )
                        ) ORDER BY ".$this->db->protect_identifiers('index');
            $query  = $this->db->query($SQL);
            self::$__cms_model_properties['widget'] = $query->result();
            self::$__cms_model_properties['is_widget_cached'] = TRUE;
        }
        $result = array();
        foreach (self::$__cms_model_properties['widget'] as $row) {
            if(isset($slug) && $slug != ''){
                if($row->active != 1 || stripos($row->slug===NULL?'':$row->slug, $slug) === FALSE){
                    continue;
                }
            }
            
            if(isset($widget_name)){
                if(strtolower($row->widget_name) != strtolower($widget_name)){
                    continue;
                }
            }


            // generate widget content
            $content = '';
            if ($row->is_static == 1) {
                $content = $row->static_content;
                if(substr($row->widget_name, 0,8)!='section_' && $content != '' && $this->cms_editing_mode() && $this->cms_allow_navigate('main_widget_management')){
                    $content = '<div class="row" style="padding-top:10px; padding-bottom:10px;"><a class="btn btn-primary pull-right" href="{{ SITE_URL }}main/widget/edit/'.$row->widget_id.'">'.
                        '<i class="glyphicon glyphicon-pencil"></i>'.
                        '</a></div>'.$content;
                } 
            } else {
                // url
                $url = $row->url;
                // content
                if($slug){
                    $content .= '<div id="__cms_widget_' . $row->widget_id . '">';
                }else{
                    $content .= '<span id="__cms_widget_' . $row->widget_id . '" style="padding:0px; margin:0px;">';
                }
                if (strpos(strtoupper($url), 'HTTP://') !== FALSE || strpos(strtoupper($url), 'HTTPS://') !== FALSE) {
                    $response = NULL;
                    // use CURL
                    if (in_array('curl', get_loaded_extensions())) {
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_COOKIEJAR, '');
                        curl_setopt($ch, CURLOPT_COOKIESESSION, true);
                        curl_setopt($ch, CURLOPT_URL, $url);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                        $response = @curl_exec($ch);
                        curl_close($ch);
                    }
                    // use file get content
                    if (!isset($response)) {
                        $response = @file_get_contents($url);
                    }
                    // add the content
                    if (isset($response)) {
                        $response = preg_replace('#(href|src|action)="([^:"]*)(?:")#', '$1="' . $url . '/$2"', $response);
                        $content .= $response;
                    }
                } else {
                    $url = trim_slashes($url);
                    $url_segment = explode('/', $url);
                    $module_path = $url_segment[0];
                    $response = '';
                    // ensure self::$__cms_model_properties['module_name'] exists. This variable's keys are all available module path
                    $this->cms_module_name();
                    if($module_path == 'main' || (array_key_exists($module_path, self::$__cms_model_properties['module_name']) && self::$__cms_model_properties['module_name'][$module_path] != '') ){
                        $_REQUEST['__cms_dynamic_widget'] = 'TRUE';
                        $_REQUEST['__cms_dynamic_widget_module'] = $module_path;
                        $url = trim($url, '/');
                        $response = @Modules::run($url);
                        if(strlen($response) == 0){
                            $response = @Modules::run($url.'/index');
                        }
                        unset($_REQUEST['__cms_dynamic_widget']);
                        unset($_REQUEST['__cms_dynamic_widget_module']);
                    }
                    // fallback, Modules::run failed, use AJAX instead
                    if(strlen($response)==0){
                        $response = '<script type="text/javascript">';
                        $response .= '$(document).ready(function(){$("#__cms_widget_' . $row->widget_id . '").load("'.site_url($url).'?__cms_dynamic_widget=TRUE");});';
                        $response .= '</script>';
                    }
                    $content .= $response;
                }

                if($slug){
                    $content .= '</div>';
                }else{
                    $content .= '</span>';
                }
            }
            // make widget based on slug
            $slugs = explode(',', $row->slug);
            foreach ($slugs as $single_slug) {
                $single_slug = trim($single_slug);
                if (!isset($result[$single_slug])) {
                    $result[$single_slug] = array();
                }
                $result[$single_slug][] = array(
                    "widget_id" => $row->widget_id,
                    "widget_name" => $row->widget_name,
                    "title" => $this->cms_lang($row->title),
                    "description" => $row->description,
                    "content" => $this->cms_parse_keyword($content)
                );
            }

        }
        return $result;
    }

    /**
     * @author  goFrendiAsgard
     * @param   string navigation_name
     * @return  string
     * @desc    return url of navigation
     */
    public function cms_navigation_url($navigation_name)
    {
        $query = $this->db->select('navigation_name, url')
            ->from(cms_table_name('main_navigation'))
            ->where('navigation_name', $navigation_name)
            ->get();
        if($query->num_rows() > 0){
            $row = $query->row();
            $url = $row->url;
            if($url == '' || $url === NULL){
                $navigation_name = $row->navigation_name;
                $url = 'main/static_page/'.$navigation_name; 
            }
            return $url;
        }else{
            return '';
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string navigation_name
     * @return  string
     * @desc    return submenu screen
     */
    public function cms_submenu_screen($navigation_name)
    {
        $submenus = array();
        if (!isset($navigation_name)) {
            $submenus = $this->cms_navigations(NULL, 1);
        } else {
            // unused, just called to ensure that the navigation is already cached
            if(!self::$__cms_model_properties['is_navigation_cached']){
                $this->cms_navigations();
            }
            $navigations = self::$__cms_model_properties['navigation'];
            $found = FALSE;
            foreach($navigations as $navigation){
                if($navigation->navigation_name == $navigation_name){
                    $found = TRUE;
                    $navigation_id = $navigation->navigation_id;
                    $submenus = $this->cms_navigations($navigation_id, 1);
                    break;
                }
            }
            if(!$found){
                return '';
            }
        }

        $html = '
        <script type="text/javascript">
            function __adjust_component(identifier){
                var max_height = 0;
                $(identifier).each(function(){
                    $(this).css("margin-bottom", 0);
                    if($(this).height()>max_height){
                        max_height = $(this).height();
                    }
                });
                $(identifier).each(function(){
                    $(this).height(max_height);
                    var margin_bottom = 0;
                    if($(this).height()<max_height){
                        margin_bottom = max_height - $(this).height();
                    }
                    margin_bottom += 10;
                    $(this).css("margin-bottom", margin_bottom);
                });
            }
            function __adjust_thumbnail_submenu(){
                __adjust_component(".thumbnail_submenu img");
                __adjust_component(".thumbnail_submenu div.caption");
                __adjust_component(".thumbnail_submenu");
            }
            $(window).load(function(){
                __adjust_thumbnail_submenu();
                // resize
                $(window).resize(function(){
                    __adjust_thumbnail_submenu();
                });
            });
        </script>';

        $html .= '<div class="row">';
        $module_path = $this->cms_module_path();
        $image_directories = array();
        if($module_path != ''){
           $image_directories[] = "modules/$module_path/assets/navigation_icon";
        }
        $image_directories[] = "assets/nocms/navigation_icon";
        foreach($this->cms_get_module_list() as $module_list){
            $other_module_path = $module_list['module_path'];
            $image_directories[] = "modules/$other_module_path/assets/navigation_icon";
        }
        $submenu_count = count($submenus);
        foreach ($submenus as $submenu) {
            $navigation_id   = $submenu["navigation_id"];
            $navigation_name = $submenu["navigation_name"];
            $title           = $submenu["title"];
            $url             = $submenu["url"];
            $description     = $submenu["description"];
            $allowed         = $submenu["allowed"];
            $notif_url       = $submenu["notif_url"];
            if (!$allowed) continue;

            // check image in current module

            $image_file_names = array();
            $image_file_names[] = $navigation_name.'.png';
            if($module_path !== '' && $module_path !== 'main'){
                $module_prefix = cms_module_prefix($this->cms_module_path());
                $navigation_parts = explode('_', $navigation_name);
                if(count($navigation_parts)>0 && $navigation_parts[0] == $module_prefix){
                    $image_file_names[] = substr($navigation_name, strlen($module_prefix)+1).'.png';
                }
            }
            $image_file_path = '';
            foreach($image_directories as $image_directory){
                foreach($image_file_names as $image_file_name){
                    $image_file_path  = $image_directory.'/'.$image_file_name;
                    if (!file_exists($image_file_path)) {
                        $image_file_path = '';
                    }
                    if ($image_file_path !== ''){
                        break;
                    }
                }
                if ($image_file_path !== ''){
                    break;
                }
            }

            $badge = '';
            if($notif_url != ''){
                $badge_id = '__cms_notif_submenu_screen_'.$navigation_id;
                $badge = '&nbsp;<span id="'.$badge_id.'" class="badge"></span>';
                $badge.= '<script type="text/javascript">
                        $(window).load(function(){
                            setInterval(function(){
                                $.ajax({
                                    dataType:"json",
                                    url: "'.addslashes($notif_url).'",
                                    success: function(response){
                                        if(response.success){
                                            $("#'.$badge_id.'").html(response.notif);
                                        }
                                        __adjust_thumbnail_submenu();
                                    }
                                });
                            }, 300000);
                        });
                    </script>
                ';
            }


            // default icon
            if ($image_file_path == '') {
                $image_file_path = 'assets/nocms/images/icons/package.png';
            }
            $html .= '<a href="' . $url . '" style="text-decoration:none;">';
            if($submenu_count <= 2){
                $html .= '<div class="col-xs-12 col-sm-6 col-md-6 col-lg-6">';
            }else if($submenu_count % 3 == 0){
                $html .= '<div class="col-xs-12 col-sm-4 col-md-4 col-lg-4">';
            }else{
                $html .= '<div class="col-xs-12 col-sm-6 col-md-4 col-lg-3">';
            }
            $html .= '<div class="thumbnail thumbnail_submenu">';

            if ($image_file_path != '') {
                $html .= '<img style="margin-top:10px; max-height:60px;" src="' . base_url($image_file_path) . '" />';
            }

            $html .= '<div class="caption">';
            $html .= '<h4>'.$title.$badge.'</h4>';
            $html .= '<p>'.$description.'</p>';
            $html .= '</div>'; // end of div.caption
            $html .= '</div>'; // end of div.thumbnail
            $html .= '</div>'; // end of div.col-xs-6 col-sm-4 col-md-3
            $html .= '</a>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @author  goFrendiAsgard
     * @param   string navigation_name
     * @return  mixed
     * @desc    return navigation path, used for layout
     */
    public function cms_get_navigation_path($navigation_name = NULL)
    {
        if (!isset($navigation_name)){
            return array();
        }
        // unused, just called to ensure that the navigation is already cached
        if(!self::$__cms_model_properties['is_navigation_cached']){
            $this->cms_navigations();
        }
        $navigations = self::$__cms_model_properties['navigation'];
        // get first node
        $result = array();
        $parent_navigation_id = NULL;
        foreach($navigations as $navigation){
            if($navigation->navigation_name == $navigation_name){
                $result[] = array(
                        'navigation_id' => $navigation->navigation_id,
                        'navigation_name' => $navigation->navigation_name,
                        'title' => $this->cms_lang($navigation->title),
                        'description' => $navigation->description,
                        'url' => $navigation->url
                    );
                $parent_navigation_id = $navigation->parent_id;
                break;
            }
        }
        while($parent_navigation_id != NULL){
            foreach($navigations as $navigation){
                if($navigation->navigation_id == $parent_navigation_id){
                    $result[] = array(
                            'navigation_id' => $navigation->navigation_id,
                            'navigation_name' => $navigation->navigation_name,
                            'title' => $this->cms_lang($navigation->title),
                            'description' => $navigation->description,
                            'url' => $navigation->url
                        );
                    $parent_navigation_id = $navigation->parent_id;
                    break;
                }
            }
        }
        //result should be in reverse order
        for ($i = 0; $i < ceil(count($result) / 2); $i++) {
            $temp                            = $result[$i];
            $result[$i]                      = $result[count($result) - 1 - $i];
            $result[count($result) - 1 - $i] = $temp;
        }
        return $result;
    }

    /**
     * @author  goFrendiAsgard
     * @return  mixed
     * @desc    return privileges of current user
     */
    public function cms_privileges()
    {
        $user_name  = $this->cms_user_name();
        $user_id    = $this->cms_user_id();
        $user_id    = !isset($user_id)||is_null($user_id)?0:$user_id;
        $not_login  = !isset($user_name) ? "TRUE" : "FALSE";
        $login      = isset($user_name) ? "TRUE" : "FALSE";
        $super_user = $user_id == 1 ? "TRUE" : "FALSE";

        $query  = $this->db->query("SELECT privilege_name, title, description
                FROM ".cms_table_name('main_privilege')." AS p WHERE
                    (authorization_id = 1) OR
                    (authorization_id = 2 AND $not_login) OR
                    (authorization_id = 3 AND $login) OR
                    (
                        (authorization_id = 4 AND $login AND
                        (
                            (SELECT COUNT(*) FROM ".cms_table_name('main_group_user')." AS gu WHERE gu.group_id=1 AND gu.user_id ='" . addslashes($user_id) . "')>0
                                OR $super_user OR
                            (SELECT COUNT(*) FROM ".cms_table_name('main_group_privilege')." AS gp
                                WHERE
                                    gp.privilege_id=p.privilege_id AND
                                    gp.group_id IN
                                        (SELECT group_id FROM ".cms_table_name('main_group_user')." WHERE user_id = '" . addslashes($user_id) . "')
                            )>0)
                        )
                    ) OR
                    (
                        (authorization_id = 5 AND $login AND
                        (
                            (SELECT COUNT(*) FROM ".cms_table_name('main_group_privilege')." AS gp
                                WHERE
                                    gp.privilege_id=p.privilege_id AND
                                    gp.group_id IN
                                        (SELECT group_id FROM ".cms_table_name('main_group_user')." WHERE user_id = '" . addslashes($user_id) . "')
                            )>0)
                        )
                    )
                    ");
        $result = array();
        foreach ($query->result() as $row) {
            $result[] = array(
                "privilege_name" => $row->privilege_name,
                "title" => $row->title,
                "description" => $row->description
            );
        }
        return $result;
    }

    /**
     * @author  goFrendiAsgard
     * @param   string navigation_name
     * @param   mixed navigations
     * @return  bool
     * @desc    check if user authorized to navigate into a page specified in parameter
     */
    public function cms_allow_navigate($navigation_name, $navigations = NULL)
    {
        if (!isset($navigations))
            $navigations = $this->cms_navigations();
        for ($i = 0; $i < count($navigations); $i++) {
            if ($navigation_name == $navigations[$i]["navigation_name"] && $navigations[$i]['active'] && $navigations[$i]["allowed"] == 1) {
                return true;
            } else if ($this->cms_allow_navigate($navigation_name, $navigations[$i]["child"])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @author  goFrendiAsgard
     * @param   string privilege_name
     * @return  bool
     * @desc    check if user have privilege specified in parameter
     */
    public function cms_have_privilege($privilege_name)
    {
        if($this->cms_user_id()==1) return TRUE;
        else{
            $privileges = $this->cms_privileges();
            for ($i = 0; $i < count($privileges); $i++) {
                if ($privilege_name == $privileges[$i]["privilege_name"])
                    return TRUE;
            }
            return FALSE;
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string identity
     * @param   string password
     * @return  bool
     * @desc    login with identity and password. Identity can be user_name or e-mail
     */
    public function cms_do_login($identity, $password)
    {
        $query = $this->db->query("SELECT user_id, user_name, real_name, email FROM ".cms_table_name('main_user')." WHERE
                    (user_name = '" . addslashes($identity) . "' OR email = '" . addslashes($identity) . "') AND
                    password = '" . cms_md5($password) . "' AND
                    active = 1");
        $user_name = NULL;
        $user_id = NULL;
        $user_real_name = NULL;
        $user_email = NULL;
        $login_succeed = FALSE;
        if($query->num_rows()>0){
            $row            = $query->row();
            $user_name      = $row->user_name;
            $user_id        = $row->user_id;
            $user_real_name = $row->real_name;
            $user_email     = $row->email;
            $login_succeed  = TRUE;
        }else{
            $this->load->helper('cms_extended_login');
            if(function_exists('extended_login')){
                $extended_login_result = extended_login($identity, $password);
                if($extended_login_result !== FALSE){
                    $query = $this->db->select('user_id, user_name')
                        ->from(cms_table_name('main_user'))
                        ->where('user_name', $identity)
                        ->get();
                    // if already exists in database
                    if($query->num_rows()>0){
                        $row = $query->row();
                        $user_id = $row->user_id;
                        $user_name = $row->user_name;
                        $login_succeed = TRUE;
                    }else{
                        $data = array();
                        $data['user_name'] = $identity;
                        $data['password'] = NULL;
                        $login_succeed = $this->db->insert(cms_table_name('main_user'), $data);
                        if($login_succeed){
                            $user_id = $this->db->insert_id();
                            $user_name = $identity;
                        }
                    }
                    if($login_succeed && is_array($extended_login_result)){
                        if(count($extended_login_result)>1){
                            $user_real_name = $extended_login_result[0];
                            $user_email = $extended_login_result[1];
                        }
                    }
                }
            }
        }

        if($login_succeed){
            $this->cms_user_name($user_name);
            $this->cms_user_id($user_id);
            $this->cms_user_real_name($user_real_name);
            $this->cms_user_email($user_email);
            
            $this->__cms_extend_user_last_active($user_id);
            return TRUE;
        }
        return FALSE;
    }

    private function __cms_extend_user_last_active($user_id){
        if($user_id > 0 && !self::$__cms_model_properties['is_user_last_active_extended']){
            $this->db->update(cms_table_name('main_user'),
                array(
                    'last_active'=>microtime(true),
                    'login'=>1),
                array('user_id'=>$user_id));
            self::$__cms_model_properties['is_user_last_active_extended'] = TRUE;
        }
    }

    /**
     * @author  goFrendiAsgard
     * @desc    logout
     */
    public function cms_do_logout()
    {
        $this->db->update(cms_table_name('main_user'),
            array('login'=>0),
            array('user_id'=>$this->cms_user_id()));
        
        $this->cms_unset_ci_session('cms_user_name');
        $this->cms_unset_ci_session('cms_user_id');
        $this->cms_unset_ci_session('cms_user_real_name');
        $this->cms_unset_ci_session('cms_user_email');
    }

    /**
     * @author  goFrendiAsgard
     * @param   string parent
     * @desc    re-arange index of navigation with certain parent_id
     */
    private function __cms_reindex_navigation($parent_id=NULL){
        if (isset($parent_id)) {
            $whereParentId = "(parent_id = $parent_id)";
        } else {
            $whereParentId = "(parent_id IS NULL)";
        }
        $query = $this->db->select('navigation_id,index')
            ->from(cms_table_name('main_navigation'))
            ->where($whereParentId)
            ->order_by('index')
            ->get();
        $index = 1;
        foreach($query->result() as $row){
            if($index != $row->index){
                $where = array('navigation_id'=>$row->navigation_id);
                $data = array('index'=>$index);
                $this->db->update(cms_table_name('main_navigation'), $data, $where);
            }
            $index += 1;
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string parent
     * @desc    re-arange index of widget
     */
    private function __cms_reindex_widget(){
        $query = $this->db->select('widget_id,index')
            ->from(cms_table_name('main_widget'))
            ->order_by('index')
            ->get();
        $index = 1;
        foreach($query->result() as $row){
            if($index != $row->index){
                $where = array('widget_id'=>$row->widget_id);
                $data = array('index'=>$index);
                $this->db->update(cms_table_name('main_widget'), $data, $where);
            }
            $index += 1;
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string parent
     * @desc    re-arange index of quicklink
     */
    private function __cms_reindex_quicklink(){
        $query = $this->db->select('quicklink_id,index')
            ->from(cms_table_name('main_quicklink'))
            ->order_by('index')
            ->get();
        $index = 1;
        foreach($query->result() as $row){
            if($index != $row->index){
                $where = array('quicklink_id'=>$row->quicklink_id);
                $data = array('index'=>$index);
                $this->db->update(cms_table_name('main_quicklink'), $data, $where);
            }
            $index += 1;
        }
    }

    public function cms_do_move_widget_after($src_widget_id, $dst_widget_id){
        $table_name = cms_table_name('main_widget');
        $this->__cms_reindex_widget();
        // get src record index
        $query = $this->db->select('index')
            ->from($table_name)
            ->where('widget_id', $src_widget_id)
            ->get();
        $row = $query->row();
        $src_index = $row->index;
        // reduce index of everything after src record
        $query = $this->db->select('widget_id, index')
            ->from($table_name)
            ->where('index >', $src_index)
            ->get();
        foreach($query->result() as $row){
            $widget_id = $row->widget_id;
            $index = $row->index - 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('widget_id' => $widget_id));
        }
        // get dst record index
        $query = $this->db->select('index')
            ->from($table_name)
            ->where('widget_id', $dst_widget_id)
            ->get();
        $row = $query->row();
        $dst_index = $row->index;
        // add index of everything after dst record
        $query = $this->db->select('widget_id, index')
            ->from($table_name)
            ->where('index >', $dst_index)
            ->get();
        foreach($query->result() as $row){
            $widget_id = $row->widget_id;
            $index = $row->index + 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('widget_id' => $widget_id));
        }
        // put src after dst
        $this->db->update($table_name,
            array('index' => $dst_index + 1),
            array('widget_id' => $src_widget_id));
        $this->__cms_reindex_widget();
    }

    public function cms_do_move_widget_before($src_widget_id, $dst_widget_id){
        $table_name = cms_table_name('main_widget');
        $this->__cms_reindex_widget();
        // get src record index
        $query = $this->db->select('index')
            ->from($table_name)
            ->where('widget_id', $src_widget_id)
            ->get();
        $row = $query->row();
        $src_index = $row->index;
        // reduce index of everything after src record
        $query = $this->db->select('widget_id, index')
            ->from($table_name)
            ->where('index >', $src_index)
            ->get();
        foreach($query->result() as $row){
            $widget_id = $row->widget_id;
            $index = $row->index - 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('widget_id' => $widget_id));
        }
        // get dst record index
        $query = $this->db->select('index')
            ->from($table_name)
            ->where('widget_id', $dst_widget_id)
            ->get();
        $row = $query->row();
        $dst_index = $row->index;
        // add index of dst record and everything after dst record
        $query = $this->db->select('widget_id, index')
            ->from($table_name)
            ->where('index >=', $dst_index)
            ->get();
        foreach($query->result() as $row){
            $widget_id = $row->widget_id;
            $index = $row->index + 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('widget_id' => $widget_id));
        }
        // put src after dst
        $this->db->update($table_name,
            array('index' => $dst_index),
            array('widget_id' => $src_widget_id));
        $this->__cms_reindex_widget();
    }

    public function cms_do_move_quicklink_after($src_quicklink_id, $dst_quicklink_id){
        $table_name = cms_table_name('main_quicklink');
        $this->__cms_reindex_quicklink();
        // get src record index
        $query = $this->db->select('index')
            ->from($table_name)
            ->where('quicklink_id', $src_quicklink_id)
            ->get();
        $row = $query->row();
        $src_index = $row->index;
        // reduce index of everything after src record
        $query = $this->db->select('quicklink_id, index')
            ->from($table_name)
            ->where('index >', $src_index)
            ->get();
        foreach($query->result() as $row){
            $quicklink_id = $row->quicklink_id;
            $index = $row->index - 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('quicklink_id' => $quicklink_id));
        }
        // get dst record index
        $query = $this->db->select('index')
            ->from($table_name)
            ->where('quicklink_id', $dst_quicklink_id)
            ->get();
        $row = $query->row();
        $dst_index = $row->index;
        // add index of everything after dst record
        $query = $this->db->select('quicklink_id, index')
            ->from($table_name)
            ->where('index >', $dst_index)
            ->get();
        foreach($query->result() as $row){
            $quicklink_id = $row->quicklink_id;
            $index = $row->index + 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('quicklink_id' => $quicklink_id));
        }
        // put src after dst
        $this->db->update($table_name,
            array('index' => $dst_index + 1),
            array('quicklink_id' => $src_quicklink_id));
        $this->__cms_reindex_quicklink();
    }

    public function cms_do_move_quicklink_before($src_quicklink_id, $dst_quicklink_id){
        $table_name = cms_table_name('main_quicklink');
        $this->__cms_reindex_quicklink();
        // get src record index
        $query = $this->db->select('index')
            ->from($table_name)
            ->where('quicklink_id', $src_quicklink_id)
            ->get();
        $row = $query->row();
        $src_index = $row->index;
        // reduce index of everything after src record
        $query = $this->db->select('quicklink_id, index')
            ->from($table_name)
            ->where('index >', $src_index)
            ->get();
        foreach($query->result() as $row){
            $quicklink_id = $row->quicklink_id;
            $index = $row->index - 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('quicklink_id' => $quicklink_id));
        }
        // get dst record index
        $query = $this->db->select('index')
            ->from($table_name)
            ->where('quicklink_id', $dst_quicklink_id)
            ->get();
        $row = $query->row();
        $dst_index = $row->index;
        // add index of dst record and everything after dst record
        $query = $this->db->select('quicklink_id, index')
            ->from($table_name)
            ->where('index >=', $dst_index)
            ->get();
        foreach($query->result() as $row){
            $quicklink_id = $row->quicklink_id;
            $index = $row->index + 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('quicklink_id' => $quicklink_id));
        }
        // put src after dst
        $this->db->update($table_name,
            array('index' => $dst_index),
            array('quicklink_id' => $src_quicklink_id));
        $this->__cms_reindex_quicklink();
    }

    public function cms_do_move_navigation_after($src_navigation_id, $dst_navigation_id){
        $table_name = cms_table_name('main_navigation');
        // get src record index
        $query = $this->db->select('parent_id, index')
            ->from($table_name)
            ->where('navigation_id', $src_navigation_id)
            ->get();
        $row = $query->row();
        $src_index = $row->index;
        $src_parent_id = $row->parent_id;
        $this->__cms_reindex_navigation($src_parent_id);
        // reduce index of everything after src record
        $query = $this->db->select('navigation_id, index')
            ->from($table_name)
            ->where('parent_id', $src_parent_id)
            ->where('index >', $src_index)
            ->get();
        foreach($query->result() as $row){
            $navigation_id = $row->navigation_id;
            $index = $row->index - 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('navigation_id' => $navigation_id));
        }
        // get dst record index
        $query = $this->db->select('parent_id, index')
            ->from($table_name)
            ->where('navigation_id', $dst_navigation_id)
            ->get();
        $row = $query->row();
        $dst_index = $row->index;
        $dst_parent_id = $row->parent_id;
        $this->__cms_reindex_navigation($dst_parent_id);
        // add index of everything after dst record
        $query = $this->db->select('navigation_id, index')
            ->from($table_name)
            ->where('parent_id', $dst_parent_id)
            ->where('index >', $dst_index)
            ->get();
        foreach($query->result() as $row){
            $navigation_id = $row->navigation_id;
            $index = $row->index + 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('navigation_id' => $navigation_id));
        }
        // put src after dst
        $this->db->update($table_name,
            array('index' => $dst_index + 1, 'parent_id' => $dst_parent_id),
            array('navigation_id' => $src_navigation_id));
        $this->__cms_reindex_navigation($src_parent_id);
        $this->__cms_reindex_navigation($dst_parent_id);
    }

    public function cms_do_move_navigation_before($src_navigation_id, $dst_navigation_id){
        $table_name = cms_table_name('main_navigation');
        // get src record index
        $query = $this->db->select('parent_id, index')
            ->from($table_name)
            ->where('navigation_id', $src_navigation_id)
            ->get();
        $row = $query->row();
        $src_index = $row->index;
        $src_parent_id = $row->parent_id;
        $this->__cms_reindex_navigation($src_parent_id);
        // reduce index of everything after src record
        $query = $this->db->select('navigation_id, index')
            ->from($table_name)
            ->where('parent_id', $src_parent_id)
            ->where('index >', $src_index)
            ->get();
        foreach($query->result() as $row){
            $navigation_id = $row->navigation_id;
            $index = $row->index - 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('navigation_id' => $navigation_id));
        }
        // get dst record index
        $query = $this->db->select('parent_id, index')
            ->from($table_name)
            ->where('navigation_id', $dst_navigation_id)
            ->get();
        $row = $query->row();
        $dst_index = $row->index;
        $dst_parent_id = $row->parent_id;
        $this->__cms_reindex_navigation($dst_parent_id);
        // add index of dst record and everything after dst record
        $query = $this->db->select('navigation_id, index')
            ->from($table_name)
            ->where('parent_id', $dst_parent_id)
            ->where('index <=', $dst_index)
            ->get();
        foreach($query->result() as $row){
            $navigation_id = $row->navigation_id;
            $index = $row->index + 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('navigation_id' => $navigation_id));
        }
        // put src after dst
        $this->db->update($table_name,
            array('index' => $dst_index, 'parent_id' => $dst_parent_id),
            array('navigation_id' => $src_navigation_id));
        $this->__cms_reindex_navigation($src_parent_id);
        $this->__cms_reindex_navigation($dst_parent_id);
    }

    public function cms_do_move_navigation_into($src_navigation_id, $dst_navigation_id){
        $table_name = cms_table_name('main_navigation');
        // get src record index
        $query = $this->db->select('parent_id, index')
            ->from($table_name)
            ->where('navigation_id', $src_navigation_id)
            ->get();
        $row = $query->row();
        $src_index = $row->index;
        $src_parent_id = $row->parent_id;
        $this->__cms_reindex_navigation($src_parent_id);
        // reduce index of everything after src record
        $query = $this->db->select('navigation_id, index')
            ->from($table_name)
            ->where('parent_id', $src_parent_id)
            ->where('index >', $src_index)
            ->get();
        foreach($query->result() as $row){
            $navigation_id = $row->navigation_id;
            $index = $row->index - 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('navigation_id' => $navigation_id));
        }
        // get dst record index
        $query = $this->db->select('parent_id, index')
            ->from($table_name)
            ->where('navigation_id', $dst_navigation_id)
            ->get();
        $row = $query->row();
        $dst_index = $row->index;
        $dst_parent_id = $row->parent_id;
        $this->__cms_reindex_navigation($dst_parent_id);
        // add index of everything inside dst record
        $query = $this->db->select('navigation_id, index')
            ->from($table_name)
            ->where('parent_id', $dst_navigation_id)
            ->get();
        foreach($query->result() as $row){
            $navigation_id = $row->navigation_id;
            $index = $row->index + 1;
            $this->db->update($table_name,
                array('index' => $index),
                array('navigation_id' => $navigation_id));
        }
        // put src after dst
        $this->db->update($table_name,
            array('index' => 1, 'parent_id' => $dst_navigation_id),
            array('navigation_id' => $src_navigation_id));
        $this->__cms_reindex_navigation($src_parent_id);
        $this->__cms_reindex_navigation($dst_id);
    }

    /**
     * @author  goFrendiAsgard
     * @param   int navigation id
     * @desc    move quicklink up
     */
    public function cms_do_move_up_quicklink($quicklink_id){
        // re-index all
        $this->__cms_reindex_quicklink();
        // get the index again
        $query = $this->db->select('quicklink_id, index')
            ->from(cms_table_name('main_quicklink'))
            ->where('quicklink_id', $quicklink_id)
            ->get();
        $row = $query->row();
        $this_index = $row->index;
        $this_quicklink_id = $row->quicklink_id;
        $SQL   = "
            SELECT max(".$this->db->protect_identifiers('index').") AS ".$this->db->protect_identifiers('index')."
            FROM ".cms_table_name('main_quicklink')." WHERE ".
            $this->db->protect_identifiers('index')."<".$this_index;
        $query = $this->db->query($SQL);
        $row   = $query->row();
        if(intval($row->index) > 0){
            $neighbor_index = intval($row->index);

            // update neighbor
            $data = array('index'=>$this_index);
            $where = $this->db->protect_identifiers('index'). ' = '.$neighbor_index;
            $this->db->update(cms_table_name('main_quicklink'),$data, $where);

            // update current row
            $data = array('index'=>$neighbor_index);
            $where = array('quicklink_id'=>$this_quicklink_id);
            $this->db->update(cms_table_name('main_quicklink'),$data, $where);
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   int navigation id
     * @desc    move quicklink down
     */
    public function cms_do_move_down_quicklink($quicklink_id){
        // re-index all
        $this->__cms_reindex_quicklink();
        // get the index again
        $query = $this->db->select('quicklink_id, index')
            ->from(cms_table_name('main_quicklink'))
            ->where('quicklink_id', $quicklink_id)
            ->get();
        $row = $query->row();
        $this_index = $row->index;
        $this_quicklink_id = $row->quicklink_id;
        $SQL   = "
            SELECT min(".$this->db->protect_identifiers('index').") AS ".$this->db->protect_identifiers('index')."
            FROM ".cms_table_name('main_quicklink')." WHERE ".
            $this->db->protect_identifiers('index').">".$this_index;
        $query = $this->db->query($SQL);
        $row   = $query->row();
        if(intval($row->index) > 0){
            $neighbor_index = intval($row->index);

            // update neighbor
            $data = array('index'=>$this_index);
            $where = $this->db->protect_identifiers('index'). ' = '.$neighbor_index;
            $this->db->update(cms_table_name('main_quicklink'),$data, $where);

            // update current row
            $data = array('index'=>$neighbor_index);
            $where = array('quicklink_id'=>$this_quicklink_id);
            $this->db->update(cms_table_name('main_quicklink'),$data, $where);
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string widget_name
     * @desc    move widget up
     */
    public function cms_do_move_up_widget($widget_name){
        // get current navigation info
        $query = $this->db->select('widget_id')
            ->from(cms_table_name('main_widget'))
            ->where('widget_name', $widget_name)
            ->get();
        $row = $query->row();
        $this_widget_id = $row->widget_id;
        // re-index all
        $this->__cms_reindex_widget();
        // get the index again
        $query = $this->db->select('index')
            ->from(cms_table_name('main_widget'))
            ->where('widget_name', $widget_name)
            ->get();
        $row = $query->row();
        $this_index = $row->index;
        $SQL   = "
            SELECT max(".$this->db->protect_identifiers('index').") AS ".$this->db->protect_identifiers('index')."
            FROM ".cms_table_name('main_widget')." WHERE ".
            $this->db->protect_identifiers('index')."<".$this_index;
        $query = $this->db->query($SQL);
        $row   = $query->row();
        if(intval($row->index) > 0){
            $neighbor_index = intval($row->index);

            // update neighbor
            $data = array('index'=>$this_index);
            $where = $this->db->protect_identifiers('index'). ' = '.$neighbor_index;
            $this->db->update(cms_table_name('main_widget'),$data, $where);

            // update current row
            $data = array('index'=>$neighbor_index);
            $where = array('widget_id'=>$this_widget_id);
            $this->db->update(cms_table_name('main_widget'),$data, $where);
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string widget_name
     * @desc    move widget down
     */
    public function cms_do_move_down_widget($widget_name){
        // get current navigation info
        $query = $this->db->select('widget_id')
            ->from(cms_table_name('main_widget'))
            ->where('widget_name', $widget_name)
            ->get();
        $row = $query->row();
        $this_widget_id = $row->widget_id;
        // re-index all
        $this->__cms_reindex_widget();
        // get the index again
        $query = $this->db->select('index')
            ->from(cms_table_name('main_widget'))
            ->where('widget_name', $widget_name)
            ->get();
        $row = $query->row();
        $this_index = $row->index;
        $SQL   = "
            SELECT min(".$this->db->protect_identifiers('index').") AS ".$this->db->protect_identifiers('index')."
            FROM ".cms_table_name('main_widget')." WHERE ".
            $this->db->protect_identifiers('index').">".$this_index;
        $query = $this->db->query($SQL);
        $row   = $query->row();
        if(intval($row->index) > 0){
            $neighbor_index = intval($row->index);

            // update neighbor
            $data = array('index'=>$this_index);
            $where = $this->db->protect_identifiers('index'). ' = '.$neighbor_index;
            $this->db->update(cms_table_name('main_widget'),$data, $where);

            // update current row
            $data = array('index'=>$neighbor_index);
            $where = array('widget_id'=>$this_widget_id);
            $this->db->update(cms_table_name('main_widget'),$data, $where);
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string navigation_name
     * @desc    move navigation up
     */
    public function cms_do_move_up_navigation($navigation_name){
        // get current navigation info
        $query = $this->db->select('parent_id, navigation_id')
            ->from(cms_table_name('main_navigation'))
            ->where('navigation_name', $navigation_name)
            ->get();
        $row = $query->row();
        $parent_id = $row->parent_id;
        $this_navigation_id = $row->navigation_id;
        // re-index all
        $this->__cms_reindex_navigation($parent_id);
        // get the index again
        $query = $this->db->select('index')
            ->from(cms_table_name('main_navigation'))
            ->where('navigation_name', $navigation_name)
            ->get();
        $row = $query->row();
        $this_index = $row->index;
        // select
        if (isset($parent_id)) {
            $whereParentId = "(parent_id = $parent_id)";
        } else {
            $whereParentId = "(parent_id IS NULL)";
        }
        $SQL   = "
            SELECT max(".$this->db->protect_identifiers('index').") AS ".$this->db->protect_identifiers('index')."
            FROM ".cms_table_name('main_navigation')." WHERE $whereParentId AND ".
            $this->db->protect_identifiers('index')."<".$this_index;
        $query = $this->db->query($SQL);
        $row   = $query->row();
        if(intval($row->index) > 0){
            $neighbor_index = intval($row->index);

            // update neighbor
            $data = array('index'=>$this_index);
            $where = $whereParentId. ' AND ' . $this->db->protect_identifiers('index'). ' = '.$neighbor_index;
            $this->db->update(cms_table_name('main_navigation'),$data, $where);

            // update current row
            $data = array('index'=>$neighbor_index);
            $where = array('navigation_id'=>$this_navigation_id);
            $this->db->update(cms_table_name('main_navigation'),$data, $where);
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string navigation_name
     * @desc    move navigation down
     */
    public function cms_do_move_down_navigation($navigation_name){
        // get current navigation info
        $query = $this->db->select('parent_id, navigation_id')
            ->from(cms_table_name('main_navigation'))
            ->where('navigation_name', $navigation_name)
            ->get();
        $row = $query->row();
        $parent_id = $row->parent_id;
        $this_navigation_id = $row->navigation_id;
        // re-index all
        $this->__cms_reindex_navigation($parent_id);
        // get the index again
        $query = $this->db->select('index')
            ->from(cms_table_name('main_navigation'))
            ->where('navigation_name', $navigation_name)
            ->get();
        $row = $query->row();
        $this_index = $row->index;
        // select
        if (isset($parent_id)) {
            $whereParentId = "(parent_id = $parent_id)";
        } else {
            $whereParentId = "(parent_id IS NULL)";
        }
        $SQL   = "
            SELECT min(".$this->db->protect_identifiers('index').") AS ".$this->db->protect_identifiers('index')."
            FROM ".cms_table_name('main_navigation')." WHERE $whereParentId AND ".
            $this->db->protect_identifiers('index').">".$this_index;
        $query = $this->db->query($SQL);
        $row   = $query->row();
        if(intval($row->index) > 0){
            $neighbor_index = intval($row->index);

            // update neighbor
            $data = array('index'=>$this_index);
            $where = $whereParentId. ' AND ' . $this->db->protect_identifiers('index'). ' = '.$neighbor_index;
            $this->db->update(cms_table_name('main_navigation'),$data, $where);
            // update current row
            $data = array('index'=>$neighbor_index);
            $where = array('navigation_id'=>$this_navigation_id);
            $this->db->update(cms_table_name('main_navigation'),$data, $where);
        }

    }

    /**
     * @author  goFrendiAsgard
     * @param   string user_name
     * @param   string email
     * @param   string real_name
     * @param   string password
     * @param   string config
     * @desc    register new user
     */
    public function cms_do_register($user_name, $email, $real_name, $password, $subsite_config=array())
    {
        // check if activation needed
        $activation = $this->cms_get_config('cms_signup_activation');
        $data = array(
            "user_name" => $user_name,
            "email" => $email,
            "real_name" => $real_name,
            "password" => cms_md5($password),
            "active" => $activation == 'automatic'
        );
        $this->db->insert(cms_table_name('main_user'), $data);
        // send activation code if needed
        if ($activation == 'by_mail') {
            $this->cms_generate_activation_code($user_name, TRUE, 'SIGNUP');
        }

        if($this->cms_is_module_active('gofrendi.noCMS.multisite') && $this->cms_get_config('cms_add_subsite_on_register') == 'TRUE'){
            $current_user_id = $this->db->select('user_id')
                ->from(cms_table_name('main_user'))
                ->where('user_name', $user_name)
                ->get()->row()->user_id;
            $module_path = $this->cms_module_path('gofrendi.noCMS.multisite');
            $this->load->model('installer/install_model');
            $this->load->model($module_path.'/subsite_model');
            // get these from old setting
            $this->install_model->db_table_prefix              = cms_table_prefix();
            $this->install_model->is_subsite                   = TRUE;
            $this->install_model->subsite                      = $user_name;
            $this->install_model->subsite_aliases              = '';
            $this->install_model->set_subsite();
            $this->install_model->admin_email                  = $email;
            $this->install_model->admin_real_name              = $real_name;
            $this->install_model->admin_user_name              = $user_name;
            $this->install_model->admin_password               = $password;
            $this->install_model->admin_confirm_password       = $password;
            $this->install_model->hide_index                   = TRUE;
            $this->install_model->gzip_compression             = FALSE;
            // get these from configuration
            $configs = $this->cms_get_config('cms_subsite_configs');
            $configs = @json_decode($configs, TRUE);
            if(!$configs){
                $configs = array();
            }
            foreach($subsite_config as $key => $value){
                $configs[$key] = $value;
            }
            $modules = $this->cms_get_config('cms_subsite_modules');
            $modules = explode(',', $modules);
            $new_modules = array();
            foreach($modules as $module){
                $module = trim($module);
                if(!in_array($module, $new_modules)){
                    $new_modules[] = $module;
                }
            }
            $modules = $new_modules;
            $this->install_model->configs = $configs;
            $this->install_model->modules = $modules;
            // check installation
            $check_installation = $this->install_model->check_installation();
            $success = $check_installation['success'];
            $module_installed = FALSE;
            if($success){
                $config = array('subsite_home_content'=> $this->cms_get_config('cms_subsite_home_content', TRUE));                
                $this->install_model->build_configuration($config);
                $this->install_model->build_database($config);
                $module_installed = $this->install_model->install_modules();
            }
            if(!isset($_SESSION)){
                session_start();
            }
            // hack module path by changing the session, don't forget to unset !!!
            $this->cms_override_module_path($module_path);
            $data = array(
                'name'=> $this->install_model->subsite,
                'description'=>$user_name.' website',
                'use_subdomain'=>$this->cms_get_config('cms_subsite_use_subdomain')=='TRUE'?1:0,
                'user_id'=>$current_user_id,
                'active'=>$activation == 'automatic'
            );
            $this->db->insert($this->cms_complete_table_name('subsite'), $data);
            $this->load->model($this->cms_module_path().'/subsite_model');
            $this->subsite_model->update_configs();
            $this->cms_reset_overridden_module_path();

            
            // get the new subsite
            $this->cms_override_module_path($module_path);
            $t_user = cms_table_name('main_user');
            $t_subsite = $this->cms_complete_table_name('subsite');
            $query = $this->db->select('name,use_subdomain')
                ->from($t_subsite)
                ->join($t_user, $t_user.'.user_id='.$t_subsite.'.user_id')
                ->where('user_name', $user_name)
                ->order_by($t_subsite.'.id', 'desc')
                ->get();
            $this->cms_reset_overridden_module_path();
            if($query->num_rows()>0){
                $row = $query->row();
                $subsite = $row->name;
                // get directory
                $site_url = site_url();
                $site_url = substr($site_url, 0, strlen($site_url)-1);
                $site_url_part = explode('/', $site_url);
                if(count($site_url_part)>3){
                    $directory_part = array_slice($site_url_part, 3);
                    log_message('error',print_r(array($directory_part,$site_url_part), TRUE));
                    $directory = '/'.implode('/', $directory_part);
                }else{
                    $directory = '';
                }
                $protocol = stripos($_SERVER['SERVER_PROTOCOL'],'https') === true ? 'https://' : 'http://';
                $ssl = $protocol == 'https://';
                $port = $_SERVER['SERVER_PORT'];
                $port = ((!$ssl && $port=='80') || ($ssl && $port=='443')) ? '' : ':'.$port;
                if($row->use_subdomain){
                    $url = $protocol.$subsite.'.'.$_SERVER['SERVER_NAME'].$port.$directory;
                }else{
                    $url = $protocol.$_SERVER['SERVER_NAME'].$port.$directory.'/site-'.$subsite;
                }
                $this->cms_do_login($user_name, $password);
                redirect($url,'refresh');
            }
        }else{
            if ($activation == 'automatic') {
                $this->cms_do_login($user_name, $password);
            }
        }

    }

    /**
     * @author  goFrendiAsgard
     * @param   string tmp_module_path
     * @desc    pretend to be tmp_module_path to adjust the table prefix. This only affect table name
     */
    public function cms_override_module_path($tmp_module_path){
        if(!isset($_SESSION)){
            session_start();
        }
        $_SESSION['__cms_override_module_path'] = $tmp_module_path;
    }

    /**
     * @author  goFrendiAsgard
     * @desc    cancel effect created by cms_override_module_path
     */
    public function cms_reset_overridden_module_path(){
        if(!isset($_SESSION)){
            session_start();
        }
        unset($_SESSION['__cms_override_module_path']);
    }

    /**
     * @author  goFrendiAsgard
     * @param   string content
     * @desc    flash content to be served as metadata on next call of $this->view in controller
     */
    public function cms_flash_metadata($content){
        if(!isset($_SESSION)){
            session_start();
        }
        if(!isset($_SESSION['__cms_flash_metadata'])){
            $_SESSION['__cms_flash_metadata'] = '';
        }
        $_SESSION['__cms_flash_metadata'] .= $content;
    }

    /**
     * @author  goFrendiAsgard
     * @param   string user_id
     * @param   string email
     * @param   string real_name
     * @param   string password
     * @desc    change current profile (user_name, email, real_name and password)
     */
    public function cms_do_change_profile($email, $real_name, $password = NULL, $user_id = NULL)
    {
        $user_id = $user_id === NULL? $this->cms_user_id() : $user_id;
        $query = $this->db->select('user_id, user_name')
            ->from(cms_table_name('main_user'))
            ->where('user_id', $user_id)
            ->get();
        if($query->num_rows()>0){
            $user_row = $query->row();
            $user_name = $user_row->user_name;
            // update current user table
            $data = array(
                "email" => $email,
                "real_name" => $real_name,
                "active" => 1
            );
            if (isset($password)) {
                $data['password'] = cms_md5($password);
            }
            $where = array(
                "user_id" => $user_id
            );        
            $this->db->update(cms_table_name('main_user'), $data, $where);
            // update other table
            if($this->cms_is_module_active('gofrendi.noCMS.multisite')){
                if(CMS_SUBSITE == ''){
                    $this->cms_override_module_path($this->cms_module_path('gofrendi.noCMS.multisite'));
                    $query = $this->db->select('name')
                        ->from($this->cms_complete_table_name('subsite'))
                        ->where('user_id', $user_row->user_id)
                        ->get();
                    $this->cms_reset_overridden_module_path();
                    if($query->num_rows()>0){
                        $subsite_row = $query->row();
                        // get user
                        include(APPPATH.'config/site-'.$subsite_row->name.'/cms_config.php');
                        $chipper = $config['__cms_chipper'];
                        $table_prefix = $config['__cms_table_prefix'];
                        $user_table = $table_prefix.'_main_user';
                        $where = array('user_id'=>1, 'user_name'=>$user_name);
                        $this->db->update($user_table, $data, $where);
                    }
                }
            }else if(CMS_SUBSITE != ''){
                if($user_row->user_id == 1){
                    include(APPPATH.'config/main/cms_config.php');
                    $chipper = $config['__cms_chipper'];
                    $cms_table_prefix = $config['__cms_table_prefix'];
                    include(FCPATH.'modules/multisite/config/module_config.php');
                    $module_table_prefix = $config['module_table_prefix'];
                    $query = $this->db->select('user_id')
                        ->from($cms_table_prefix.'_'.$module_table_prefix.'_subsite')
                        ->where('name', CMS_SUBSITE)
                        ->get();
                    if($query->num_rows()>0){
                        $subsite_row = $query->row();
                        $query = $this->db->select('user_id')
                            ->from($cms_table_prefix.'_main_user')
                            ->where('user_id', $subsite_row->user_id)
                            ->get();
                        if($query->num_rows()>0){
                            $where = array('user_id'=>$subsite_row->user_id);
                            $user_table = $cms_table_prefix.'_main_user';
                            if (isset($password)) {
                                $data['password'] = cms_md5($password, $chipper);
                            }
                            $this->db->update($user_table, $data, $where);    
                        }
                    }
                }
            }
            if($user_id == $this->cms_user_id()){
                $this->cms_user_name($user_name);
                $this->cms_user_email($email);
                $this->cms_user_real_name($real_name);
            }
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string module_name
     * @return  bool
     * @desc    checked if module installed
     */
    public function cms_is_module_active($module_name)
    {
        if (!self::$__cms_model_properties['is_module_path_cached']) {
            $this->cms_adjust_module();
        }
        if(array_key_exists($module_name, self::$__cms_model_properties['module_path'])){
            return true;
        }
        return false;
    }

    /**
     * @author  goFrendiAsgard
     * @return  mixed
     * @desc    get module list
     */
    public function cms_get_module_list($keyword = NULL)
    {        
        $this->load->helper('directory');
        $directories = directory_map(FCPATH.'modules', 1);
        sort($directories);
        $module      = array();
        foreach ($directories as $directory) {
            $directory = str_replace(array('/','\\'),'',$directory);
            if (!is_dir(FCPATH.'modules/' . $directory))
                continue;

            if (!file_exists(FCPATH.'modules/' . $directory . '/description.txt'))
                continue;

            // unpublished module should not be shown
            if(CMS_SUBSITE != ''){
                $subsite_auth_file = FCPATH.'modules/' . $directory . '/subsite_auth.php';
                if (file_exists($subsite_auth_file)){
                    unset($public);
                    unset($subsite_allowed);
                    include($subsite_auth_file);
                    if(isset($public) && is_bool($public) && !$public){
                        if(!isset($subsite_allowed) || (is_array($subsite_allowed) && !in_array(CMS_SUBSITE, $subsite_allowed))){
                            continue;
                        }
                    }
                }
            }

            $files              = directory_map(FCPATH.'modules/' . $directory . '/controllers', 1);
            $module_controllers = array();
            foreach ($files as $file) {
                $filename_array = explode('.', $file);
                $extension      = $filename_array[count($filename_array) - 1];
                unset($filename_array[count($filename_array) - 1]);
                $filename = implode('.', $filename_array);
                if ($extension == 'php' && $filename != '_info') {
                    $module_controllers[] = $filename;
                }
            }
            $module_name = $this->cms_module_name($directory);
            $json            = file_get_contents(FCPATH.'modules/'.$directory.'/description.txt');
            $module_info     = @json_decode($json, true);
            $module_info     = $module_info === NULL? array() : $module_info;
            foreach(array('name'=>'', 'description'=>'', 'dependencies'=>array(), 'version'=>'0.0.0', 'activate'=>'info/activate', 'deactivate'=>'info/deactivate', 'upgrade'=>'info/upgrade') as $key=>$value){
                if(!array_key_exists($key, $module_info)){
                    $module_info[$key] = $value;
                }
            }
            $module_name     = $module_info['name'];
            $active          = $this->cms_is_module_active($module_name);
            $description     = $module_info['description'];
            $dependencies    = $module_info['dependencies'];
            $old             = $this->cms_module_version($module_name) < $module_info['version'];
            $current_version = $module_info['version'];
            $activate_link   = site_url($directory.'/'.$module_info['activate']);
            $deactivate_link = site_url($directory.'/'.$module_info['deactivate']);
            $upgrade_link    = site_url($directory.'/'.$module_info['upgrade']);
            $old_version     = $this->cms_module_version($module_name);
            $this->cms_reset_overridden_module_path();
            // searching
            if($keyword === NULL || ($keyword !== NULL && (
                stripos($module_name, $keyword) !== FALSE ||
                stripos($directory, $keyword) !== FALSE ||
                stripos($description, $keyword) !== FALSE
            ))){
                $module[]    = array(
                    'module_name'       => $module_name,
                    'module_path'       => $directory,
                    'active'            => $active,
                    'description'       => $description,
                    'dependencies'      => $dependencies,
                    'old'               => $old,
                    'old_version'       => $old_version,
                    'current_version'   => $current_version,
                    'controllers'       => $module_controllers,
                    'activate_link'     => $activate_link,
                    'deactivate_link'   => $deactivate_link,
                    'upgrade_link'      => $upgrade_link,
                );
            }
        }
        return $module;
    }

    private function cms_adjust_module(){
        $query = $this->db->select('module_id, version, module_name, module_path')
            ->from(cms_table_name('main_module'))
            ->get();
        foreach($query->result() as $row){  
            $json            = file_get_contents(FCPATH.'modules/'.$row->module_path.'/description.txt');
            $module_info     = @json_decode($json, true);
            $module_info     = $module_info === NULL? array() : $module_info;
            $module_name     = $row->module_name;
            if(array_key_exists('name', $module_info)){
                $module_name = $module_info['name'];
                if($row->module_name != $module_name){
                    $this->db->update(cms_table_name('main_module'),
                            array('module_name'=>$module_name),
                            array('module_id'=>$row->module_id)
                        );
                }
            }                  
            self::$__cms_model_properties['module_version'][$module_name] = $row->version;
            self::$__cms_model_properties['module_name'][$row->module_path] = $module_name;
            self::$__cms_model_properties['module_path'][$row->module_name] = $row->module_path;
        }
        self::$__cms_model_properties['is_module_version_cached'] = TRUE;
        self::$__cms_model_properties['is_module_name_cached'] = TRUE;
        self::$__cms_model_properties['is_module_path_cached'] = TRUE;
    }

    public function cms_module_version($module_name = NULL, $new_version = NULL){
        if($new_version !== NULL){
            $this->db->update(cms_table_name('main_module'),
                array('version'=>$new_version),
                array('module_name'=>$module_name));
        }
        if (!self::$__cms_model_properties['is_module_version_cached']) {
            $this->cms_adjust_module();
        }
        if(array_key_exists($module_name, self::$__cms_model_properties['module_version'])){
            return self::$__cms_model_properties['module_version'][$module_name];
        }
        return '0.0.0';
    }

    /**
     * @author  goFrendiAsgard
     * @param   string module_name
     * @return  string
     * @desc    get module_path (folder name) of specified module_name (name space)
     */
    public function cms_module_path($module_name = NULL)
    {
        // hack module path by changing the session, don't forget to unset !!!
        if(isset($_SESSION['__cms_override_module_path'])){
            return $_SESSION['__cms_override_module_path'];
        }else{
            if (!isset($module_name) || $module_name === NULL) {
                if(isset($_REQUEST['__cms_dynamic_widget_module'])){
                    $module = $_REQUEST['__cms_dynamic_widget_module'];
                }else{
                    $module = $this->router->fetch_module();
                }
                return $module;
            } else {
                if (!self::$__cms_model_properties['is_module_path_cached']) {
                    $this->cms_adjust_module();
                }
                if(array_key_exists($module_name, self::$__cms_model_properties['module_path'])){
                    return self::$__cms_model_properties['module_path'][$module_name];
                }
                return '';
            }
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string module_path
     * @return  string
     * @desc    get module_name (name space) of specified module_path (folder name)
     */
    public function cms_module_name($module_path = NULL)
    {
        if(!isset($module_path) || is_null($module_path)){
            $module_path = $this->cms_module_path();
        }

        if (!self::$__cms_model_properties['is_module_name_cached']) {
            $this->cms_adjust_module();
        }
        if(array_key_exists($module_path, self::$__cms_model_properties['module_name'])){
            return self::$__cms_model_properties['module_name'][$module_path];
        }
        return '';
    }

    /**
     * @author  goFrendiAsgard
     * @return  mixed
     * @desc    get theme list
     */
    public function cms_get_theme_list($keyword=NULL)
    {
        $this->load->helper('directory');
        $directories = directory_map(FCPATH.'themes', 1);
        sort($directories);
        $themes      = array();
        foreach ($directories as $directory) {
            $directory = str_replace(array('/','\\'),'',$directory);
            if (!is_dir(FCPATH.'themes/' . $directory))
                continue;

            if(CMS_SUBSITE != ''){
                $subsite_auth_file = FCPATH.'themes/'.$directory.'/subsite_auth.php';
                if(file_exists($subsite_auth_file)){
                    unset($public);
                    unset($subsite_allowed);
                    include($subsite_auth_file);
                    if(isset($public) && is_bool($public) && !$public){
                        if(!isset($subsite_allowed) || (is_array($subsite_allowed) && !in_array(CMS_SUBSITE, $subsite_allowed))){
                            continue;
                        }
                    }
                }
            }

            $layout_name = $directory;

            $description = '';
            $description_file = FCPATH.'themes/'.$directory.'/description.txt';
            if(file_exists($description_file)){
                $description = file_get_contents($description_file);
            }

            if($keyword === NULL  || ($keyword !== NULL && (stripos($directory, $keyword)!== FAlSE || stripos($description, $keyword) !== FALSE))){
                $themes[] = array(
                    "path" => $directory,
                    "description" => $description,
                    "used" => $this->cms_get_config('site_theme') == $layout_name
                );
            }
        }
        // the currently used theme should be on the top
        for($i=0; $i<count($themes); $i++){
            if($themes[$i]['used']){
                if($i != 0){
                    $new_themes = array();
                    $current_theme = $themes[$i];
                    $new_themes[] = $current_theme;
                    for($j=0; $j<count($themes); $j++){
                        if($j != $i){
                            $new_themes[] = $themes[$j];
                        }
                    }
                    $themes = $new_themes;
                }
                break;
            }
        }
        return $themes;
    }

    /**
     * @author  goFrendiAsgard
     * @param   string identity
     * @param    bool send_mail
     * @param   string reason (FORGOT, SIGNUP)
     * @return  bool
     * @desc    generate activation code, and send email to applicant
     */
    public function cms_generate_activation_code($identity, $send_mail = FALSE, $reason = 'FORGOT')
    {
        // if generate activation reason is "FORGOT", then user should be active
        $where_active = '1=1';
        if ($reason == 'FORGOT') {
            $where_active = 'active = TRUE';
        }
        // generate query
        $query = $this->db->query("SELECT user_name, real_name, user_id, email FROM ".cms_table_name('main_user')." WHERE
                    (user_name = '" . addslashes($identity) . "' OR email = '" . addslashes($identity) . "') AND
                    $where_active");
        if ($query->num_rows() > 0) {
            $row              = $query->row();
            $user_id          = $row->user_id;
            $email_to_address = $row->email;
            $user_name        = $row->user_name;
            $real_name        = $row->real_name;
            $activation_code  = random_string();

            //update, add activation_code
            $data  = array(
                "activation_code" => cms_md5($activation_code)
            );
            $where = array(
                "user_id" => $user_id
            );
            $this->db->update(cms_table_name('main_user'), $data, $where);
            $this->load->library('email');
            if ($send_mail) {
                //prepare activation email to user
                $email_from_address = $this->cms_get_config('cms_email_reply_address');
                $email_from_name    = $this->cms_get_config('cms_email_reply_name');

                $email_subject = 'Account Activation';
                $email_message = 'Dear, {{ user_real_name }}<br />Click <a href="{{ site_url }}main/activate/{{ activation_code }}">{{ site_url }}main/activate/{{ activation_code }}</a> to activate your account';
                if (strtoupper($reason) == 'FORGOT') {
                    $email_subject = $this->cms_get_config('cms_email_forgot_subject', TRUE);
                    $email_message = $this->cms_get_config('cms_email_forgot_message', TRUE);
                } else if (strtoupper($reason) == 'SIGNUP') {
                    $email_subject = $this->cms_get_config('cms_email_signup_subject', TRUE);
                    $email_message = $this->cms_get_config('cms_email_signup_message', TRUE);
                }

                $email_message = str_replace('{{ user_real_name }}', $real_name, $email_message);
                $email_message = str_replace('{{ activation_code }}', $activation_code, $email_message);
                //send email to user
                return $this->cms_send_email($email_from_address, $email_from_name, $email_to_address, $email_subject, $email_message);
            }
            // if send_mail == false, than it should be succeed
            return true;
        }
        return false;
    }

    /**
     * @author  goFrendiAsgard
     * @param   string activation_code
     * @param   string new_password
     * @return  bool success
     * @desc    activate user
     */
    public function cms_activate_account($activation_code, $new_password = NULL)
    {
        $query = $this->db->query("SELECT user_id FROM ".cms_table_name('main_user')." WHERE
                    (activation_code = '" . cms_md5($activation_code) . "')");
        if ($query->num_rows() > 0) {
            $row     = $query->row();
            $user_id = $row->user_id;
            $data    = array(
                "activation_code" => NULL,
                "active" => TRUE
            );
            if (isset($new_password)) {
                $data['password'] = cms_md5($new_password);
            }

            $where = array(
                "user_id" => $user_id
            );
            $this->db->update(cms_table_name('main_user'), $data, $where);

            $this->_cms_set_user_subsite_activation($user_id, 1);

            return TRUE;
        } else {
            return FALSE;
        }
    }

    public function _cms_set_user_subsite_activation($user_id, $active){
        if($this->cms_is_module_active('gofrendi.noCMS.multisite')){
            $module_path = $this->cms_module_path('gofrendi.noCMS.multisite');
            $this->cms_override_module_path($module_path);
            $data = array('active'=>$active);
            $where = array('user_id'=>$user_id);
            $this->db->update($this->cms_complete_table_name('subsite'), $data, $where);
            $this->load->model($this->cms_module_path().'/subsite_model');
            $this->subsite_model->update_configs();
            $this->cms_reset_overridden_module_path();
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string from_address
     * @param   string from_name
     * @param   string to_address
     * @param   string subject
     * @param   string message
     * @desc    send email
     */
    public function cms_send_email($from_address, $from_name, $to_address, $subject, $message)
    {
        $this->load->library('email');
        //send email to user
        $config['useragent']      = (string) $this->cms_get_config('cms_email_useragent');
        $config['protocol']       = (string) $this->cms_get_config('cms_email_protocol');
        $config['mailpath']       = (string) $this->cms_get_config('cms_email_mailpath');
        $config['smtp_host']      = (string) $this->cms_get_config('cms_email_smtp_host');
        $config['smtp_user']      = (string) $this->cms_get_config('cms_email_smtp_user');
        $config['smtp_pass']      = (string) cms_decode($this->cms_get_config('cms_email_smtp_pass'));
        $config['smtp_port']      = (integer) $this->cms_get_config('cms_email_smtp_port');
        $config['smtp_timeout']   = (integer) $this->cms_get_config('cms_email_smtp_timeout');
        $config['wordwrap']       = (boolean) $this->cms_get_config('cms_email_wordwrap');
        $config['wrapchars']      = (integer) $this->cms_get_config('cms_email_wrapchars');
        $config['mailtype']       = (string) $this->cms_get_config('cms_email_mailtype');
        $config['charset']        = (string) $this->cms_get_config('cms_email_charset');
        $config['validate']       = (boolean) $this->cms_get_config('cms_email_validate');
        $config['priority']       = (integer) $this->cms_get_config('cms_email_priority');
        $config['crlf']           = "\r\n";
        $config['newline']        = "\r\n";
        $config['bcc_batch_mode'] = (boolean) $this->cms_get_config('cms_email_bcc_batch_mode');
        $config['bcc_batch_size'] = (integer) $this->cms_get_config('cms_email_bcc_batch_size');

        $ssl = $this->email->smtp_crypto === 'ssl'? 'ssl://' : ''; 
        // if protocol is (not smtp) or (is smtp and able to connect)
        if($config['protocol'] != 'smtp' || ($config['protocol'] == 'smtp' && $this->cms_is_connect($ssl.$config['smtp_host'], $config['smtp_port']))){
            $message = $this->cms_parse_keyword($message);

            $this->email->initialize($config);
            $this->email->from($from_address, $from_name);
            $this->email->to($to_address);
            $this->email->subject($subject);
            $this->email->message($message);
            try{
                $success = $this->email->send();
                log_message('debug', $this->email->print_debugger());
            }catch(Error $error){
                $success = FALSE;
                log_message('error', $this->email->print_debugger());
            }
        }else{
            $success = FALSE;
            log_message('error', 'Connection to '.$ssl.$config['smtp_host'] . ':'. $config['smtp_port'].' is impossible');
        }
        return $success;
    }

    public function cms_resize_image($file_name, $nWidth, $nHeight){
        // original code: http://stackoverflow.com/questions/16977853/resize-images-with-transparency-in-php

        
        // read image
        $im = @imagecreatefrompng($file_name);
        if($im){
            $srcWidth = imagesx($im);
            $srcHeight = imagesy($im);

            // decide ratio
            $widthRatio = $nWidth/$srcWidth;
            $heightRatio = $nHeight/$srcHeight;
            if($widthRatio > $heightRatio){
                $ratio = $heightRatio;
            }else{
                $ratio = $heightRatio;
            }
            $nWidth = $srcWidth * $ratio;
            $nHeight = $srcHeight * $ratio;

            // make new image
            $newImg = imagecreatetruecolor($nWidth, $nHeight);
            imagealphablending($newImg, false);
            imagesavealpha($newImg,true);
            $transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127);
            imagefilledrectangle($newImg, 0, 0, $nWidth, $nHeight, $transparent);
            imagecopyresampled($newImg, $im, 0, 0, 0, 0, $nWidth, $nHeight,
                $srcWidth, $srcHeight);

            // write new image
            imagepng($newImg, $file_name);
        }else{
            $this->load->library('image_moo');
            $this->image_moo->load($file_name)->resize($nWidth,$nHeight)->save($file_name,true);
        }
    }

    /**
     * @author  goFrendiAsgard
     * @param   string activation_code
     * @return  bool
     * @desc    validate activation_code
     */
    public function cms_valid_activation_code($activation_code)
    {
        $query = $this->db->query("SELECT activation_code FROM ".cms_table_name('main_user')." WHERE
                    (activation_code = '" . cms_md5($activation_code) . "') AND
                    (activation_code IS NOT NULL)");
        if ($query->num_rows() > 0)
            return true;
        else
            return false;
    }

    /**
     * @author  goFrendiAsgard
     * @param   string name
     * @param   string value
     * @param   string description
     * @desc    set config variable
     */
    public function cms_set_config($name, $value, $description = NULL)
    {
        $query = $this->db->query("SELECT config_id FROM ".cms_table_name('main_config')." WHERE
                    config_name = '" . addslashes($name) . "'");
        if ($query->num_rows() > 0) {
            $data = array(
                "value" => $value
            );
            if (isset($description))
                $data['description'] = $description;
            $where = array(
                "config_name" => $name
            );
            $this->db->update(cms_table_name('main_config'), $data, $where);
        } else {
            $data = array(
                "value" => $value,
                "config_name" => $name
            );
            if (isset($description))
                $data['description'] = $description;
            $this->db->insert(cms_table_name('main_config'), $data);
        }
        cms_config($name, $value);
        // save as __cms_model_properties too
        self::$__cms_model_properties['config'][$name] = $value;
    }

    /**
     * @author  goFrendiAsgard
     * @param   string name
     * @desc    unset configuration variable
     */
    public function cms_unset_config($name)
    {
        $where = array(
            "config_name" => $name
        );
        $query = $this->db->delete(cms_table_name('main_config'), $where);
    }

    /**
     * @author  goFrendiAsgard
     * @param   string name, bool raw
     * @return  string
     * @desc    get configuration variable
     */
    public function cms_get_config($name, $raw = FALSE)
    {
        $value = cms_config($name);
        if($value === NULL || !$value){            
            if (!self::$__cms_model_properties['is_config_cached']) {
                $query = $this->db->select('value, config_name')
                    ->from(cms_table_name('main_config'))
                    ->get();
                foreach($query->result() as $row){
                    $value = $row->value;
                    $config_name = $row->config_name;                    
                    self::$__cms_model_properties['config'][$config_name] = $value;
                    cms_config($config_name, $value);
                    if($config_name == $name){
                        $found = TRUE;
                    }
                }
                self::$__cms_model_properties['is_config_cached'] = TRUE;
            }
            if(array_key_exists($name, self::$__cms_model_properties['config'])){
                $value = self::$__cms_model_properties['config'][$name];
            }else{
                $value = NULL;
            }
        }

        // if raw is false, then don't parse keyword
        if (!$raw && isset($value)) {
            $value = $this->cms_parse_keyword($value);
        }
        return $value;
    }

    /**
     * @author    goFrendiAsgard
     * @param    string language
     * @return    string language
     * @desc    set language for this session only
     */
    public function cms_language($language = NULL)
    {
        if (isset($language)) {
            $this->cms_ci_session('cms_lang', $language);
        } else {
            $language = '';
            $language = $this->cms_ci_session('cms_lang');
            if (!$language) {
                $language = $this->cms_get_config('site_language', True);
                $this->cms_ci_session('cms_lang', $language);
            }
            return $language;
        }
    }

    /**
     * @author    goFrendiAsgard
     * @return    array list of available languages
     * @desc    get available languages
     */
    public function cms_language_list()
    {
        // look for available language which are probably not registered
        if(!isset($_SESSION)){
            session_start();
        }
        if(!isset($_SESSION['__cms_language_uptodate'])){
            $this->load->helper('file');
            $new_lang = array();
            $language_list = get_filenames(APPPATH.'../assets/nocms/languages');
            foreach ($language_list as $language){
                if(preg_match('/\.php$/i', $language)){
                    $lang = str_ireplace('.php', '', $language);
                    $exist = $this->db->select('code')->from(cms_table_name('main_language'))
                        ->where('code',$lang)->get()->num_rows() > 0;
                    if(!$exist){
                        $new_lang[] = $lang;
                    }
                }
            }
            $module_list = $this->cms_get_module_list();
            $module_list[] = array('module_path'=>'main');
            foreach ($module_list as $module){
                $directory = $module['module_path'];
                $module_language_list = get_filenames(APPPATH.'../modules/'.$directory.'/assets/languages');
                if($module_language_list === FALSE) continue;
                foreach($module_language_list as $module_language){
                    if(preg_match('/\.php$/i', $module_language)){
                        $module_language = str_ireplace('.php', '', $module_language);
                        $exist = $this->db->select('code')->from(cms_table_name('main_language'))
                            ->where('code',$module_language)->get()->num_rows() > 0;
                        if(!$exist && !in_array($module_language, $new_lang)){
                            $new_lang[] = $module_language;
                        }
                    }
                }
            }
            // add the language to database
            foreach($new_lang as $lang){
                $this->db->insert(cms_table_name('language'),array('name'=>$lang,'code'=>$lang));
            }
            $_SESSION['__cms_language_uptodate'] = TRUE;
        }
        // grab it
        $result = $this->db->select('name,code,iso_code')
            ->from(cms_table_name('main_language'))
            ->order_by('name')
            ->get()->result();
        return $result;
    }

    /**
     * @author  goFrendiAsgard
     * @return  mixed
     * @desc    get all language dictionary
     */
    public function cms_language_dictionary()
    {
        $language = $this->cms_language();
        if (count(self::$__cms_model_properties['language_dictionary']) == 0) {
            $lang = array();

            // language setting from all modules but this current module
            $modules = $this->cms_get_module_list();
            foreach ($modules as $module) {
                $module_path = $module['module_path'];
                if ($module_path != $this->cms_module_path()) {
                    $local_language_file = APPPATH."../modules/$module_path/assets/languages/$language.php";
                    if (file_exists($local_language_file)) {
                        include($local_language_file);
                    }
                }
            }
            // nocms main module language setting override previous language setting
            $language_file = APPPATH."../modules/main/assets/languages/$language.php";
            if (file_exists($language_file)) {
                include($language_file);
            }
            // global nocms language setting override previous language setting
            $language_file = APPPATH."../assets/nocms/languages/$language.php";
            if (file_exists($language_file)) {
                include($language_file);
            }
            // language setting from current module
            $module_path         = $this->cms_module_path();
            $local_language_file = APPPATH."../modules/$module_path/assets/languages/$language.php";
            if (file_exists($local_language_file)) {
                include($local_language_file);
            }

            $result = $this->db->select('key, translation')
                ->from(cms_table_name('main_detail_language'))
                ->join(cms_table_name('main_language'), cms_table_name('main_detail_language').'.id_language = '.cms_table_name('main_language').'.language_id')
                ->where('name', $this->cms_language())
                ->get()->result();
            foreach($result as $row){
                $lang[$row->key] = $row->translation;
            }

            self::$__cms_model_properties['language_dictionary'] = $lang;
        }        
        return self::$__cms_model_properties['language_dictionary'];
    }

    /**
     * @author  goFrendiAsgard
     * @param   string key
     * @return  string
     * @desc    get translation of key in site_language
     */
    public function cms_lang($key)
    {
        $language = $this->cms_language();

        $dictionary = $this->cms_language_dictionary();
        // get the language
        if (isset($dictionary[$key])) {
            return $dictionary[$key];
        } else {
            return $key;
        }


    }

    /**
     * @author goFrendiAsgard
     * @param  string value
     * @return string
     * @desc   parse keyword like {{ site_url  }} , {{ base_url }} , {{ user_name }} , {{ language }}
     */
    public function cms_parse_keyword($value)
    {
        $value = $this->cms_escape_template($value);

        if(strpos($value, '{{ ') !== FALSE){

            $pattern     = array();
            $replacement = array();

            // user_name
            $pattern[]     = "/\{\{ user_id \}\}/si";
            $replacement[] = $this->cms_user_id();

            // user_name
            $pattern[]     = "/\{\{ user_name \}\}/si";
            $replacement[] = $this->cms_user_name();

            // user_real_name
            $pattern[]     = "/\{\{ user_real_name \}\}/si";
            $replacement[] = $this->cms_user_real_name();

            // user_email
            $pattern[]     = "/\{\{ user_email \}\}/si";
            $replacement[] = $this->cms_user_email();

            // site_url
            $site_url = site_url();
            if ($site_url[strlen($site_url) - 1] != '/')
                $site_url .= '/';
            $pattern[]     = '/\{\{ site_url \}\}/si';
            $replacement[] = $site_url;

            // base_url
            $base_url = base_url();
            if ($base_url[strlen($base_url) - 1] != '/')
                $base_url .= '/';
            if(USE_SUBDOMAIN && CMS_SUBSITE != '' && !USE_ALIAS){
                $base_url = str_ireplace('://'.CMS_SUBSITE.'.',  '://', $base_url);
            }
            $pattern[]     = '/\{\{ base_url \}\}/si';
            $replacement[] = $base_url;

            // module_path & module_name
            $module_path = $this->cms_module_path();
            $module_name = $this->cms_module_name($module_path);
            $module_site_url = site_url($module_path);
            $module_base_url = base_url('modules/'.$module_path);
            if ($module_site_url[strlen($module_site_url) - 1] != '/')
                $module_site_url .= '/';
            if ($module_base_url[strlen($module_base_url) - 1] != '/')
                $module_base_url .= '/';
            $pattern[]     = '/\{\{ module_path \}\}/si';
            $replacement[] = $module_path;
            $pattern[]     = '/\{\{ module_site_url \}\}/si';
            $replacement[] = $module_site_url;
            $pattern[]     = '/\{\{ module_base_url \}\}/si';
            $replacement[] = $module_base_url;
            $pattern[]     = '/\{\{ module_name \}\}/si';
            $replacement[] = $module_name;

            // language
            $pattern[]     = '/\{\{ language \}\}/si';
            $replacement[] = $this->cms_language();

            // execute regex
            $value = preg_replace($pattern, $replacement, $value);
        }

        // translate language
        if(strpos($value, '{{ ') !== FALSE){
            $pattern = '/\{\{ language:(.*?) \}\}/si';
            // execute regex
            $value   = preg_replace_callback($pattern, array(
                $this,
                '__cms_preg_replace_callback_lang'
            ), $value);
        }

        // if language, elif
        if(strpos($value, '{{ ') !== FALSE){
            $language    = $this->cms_language();
            $pattern     = array();
            $pattern[]   = "/\{\{ if_language:$language \}\}(.*?)\{\{ elif_language:.*?\{\{ end_if \}\}/si";
            $pattern[]   = "/\{\{ if_language:$language \}\}(.*?)\{\{ else \}\}.*?\{\{ end_if \}\}/si";
            $pattern[]   = "/\{\{ if_language:$language \}\}(.*?)\{\{ end_if \}\}/si";
            $pattern[]   = "/\{\{ if_language:.*?\{\{ elif_language:$language \}\}(.*?)\{\{ elif_language:.*?\{\{ end_if \}\}/si";
            $pattern[]   = "/\{\{ if_language:.*?\{\{ elif_language:$language \}\}(.*?)\{\{ else \}\}.*?\{\{ end_if \}\}/si";
            $pattern[]   = "/\{\{ if_language:.*?\{\{ elif_language:$language \}\}(.*?)\{\{ end_if \}\}/si";
            $pattern[]   = "/\{\{ if_language:.*?\{\{ else \}\}(.*?)\{\{ end_if \}\}/si";
            $pattern[]   = "/\{\{ if_language:.*?\{\{ end_if \}\}/si";
            $replacement = '$1';
            // execute regex
            $value       = preg_replace($pattern, $replacement, $value);
        }

        // clear un-translated language
        if(strpos($value, '{{ ') !== FALSE){
            $pattern     = array();
            $pattern     = "/\{\{ if_language:.*?\{\{ end_if \}\}/s";
            $replacement = '';
            // execute regex
            $value       = preg_replace($pattern, $replacement, $value);
        }

        // configuration
        if(strpos($value, '{{ ') !== FALSE){
            $pattern = '/\{\{ (.*?) \}\}/si';
            // execute regex
            $value   = preg_replace_callback($pattern, array(
                $this,
                '__cms_preg_replace_callback_config'
            ), $value);
        }

        return $value;
    }

    /**
     * @author goFrendiAsgard
     * @param  string user_name
     * @return bool
     * @desc   check if user already exists
     */
    public function cms_is_user_exists($identity, $exception_user_id = 0)
    {   
        $query = $this->db->query('SELECT user_id, user_name FROM '.cms_table_name('main_user').' '.
            'WHERE 
                (user_name LIKE \''.addslashes($identity).'\' OR email LIKE \''.addslashes($identity).'\') AND
                (user_id <> '.addslashes($exception_user_id).')');
        $num_rows = $query->num_rows();
        if($num_rows > 0){
            return TRUE;
        }
        return FALSE;
    }


    /**
     * @author goFrendiAsgard
     * @param  string expression
     * @return string
     * @desc return a "save" pattern which is not replace anything inside HTML tag, and
     * anything between <textarea></textarea> and <option></option>
     */
    public function cms_escape_template($str)
    {
        $pattern   = array();
        $pattern[] = '/(<textarea[^<>]*>)(.*?)(<\/textarea>)/si';
        $pattern[] = '/(value *= *")(.*?)(")/si';
        $pattern[] = "/(value *= *')(.*?)(')/si";

        $str = preg_replace_callback($pattern, array(
            $this,
            '__cms_preg_replace_callback_escape_template'
        ), $str);

        return $str;
    }

    /**
     * @author goFrendiAsgard
     * @param  string expression
     * @return string
     * @desc return an "unsave" pattern which is not replace anything inside HTML tag, and
     * anything between <textarea></textarea> and <option></option>
     */
    public function cms_unescape_template($str)
    {
        $pattern   = array();
        $pattern[] = '/(<textarea[^<>]*>)(.*?)(<\/textarea>)/si';
        $pattern[] = '/(value *= *")(.*?)(")/si';
        $pattern[] = "/(value *= *')(.*?)(')/si";
        $str       = preg_replace_callback($pattern, array(
            $this,
            '__cms_preg_replace_callback_unescape_template'
        ), $str);

        return $str;
    }

    /**
     * @author goFrendiAsgard
     * @param  array arr
     * @return string
     * @desc replace every '{{' and '}}' in $arr[1] into &#123; and &#125;
     */
    private function __cms_preg_replace_callback_unescape_template($arr)
    {
        $to_replace     = array(
            '{{ ',
            ' }}'
        );
        $to_be_replaced = array(
            '&#123;&#123; ',
            ' &#125;&#125;'
        );
        return $arr[1] . str_replace($to_be_replaced, $to_replace, $arr[2]) . $arr[3];
    }

    /**
     * @author goFrendiAsgard
     * @param  array arr
     * @return string
     * @desc replace every &#123; and &#125; in $arr[1] into '{{' and '}}';
     */
    private function __cms_preg_replace_callback_escape_template($arr)
    {
        $to_be_replaced = array(
            '{{ ',
            ' }}'
        );
        $to_replace     = array(
            '&#123;&#123; ',
            ' &#125;&#125;'
        );
        return $arr[1] . str_replace($to_be_replaced, $to_replace, $arr[2]) . $arr[3];
    }

    /**
     * @author goFrendiAsgard
     * @param  array arr
     * @return string
     * @desc replace $arr[1] with respective language;
     */
    private function __cms_preg_replace_callback_lang($arr)
    {
        return $this->cms_lang($arr[1]);
    }

    private function __cms_preg_replace_callback_config($arr)
    {
        $raw_config_value = $this->cms_get_config($arr[1]);
        if(isset($raw_config_value)){
            // avoid recursion
            if(strpos($raw_config_value, '{{ '.$arr[1].' }}') !== FALSE){
                $raw_config_value = str_replace('{{ '.$arr[1].' }}', ' ', $raw_config_value);
            }
            return $this->cms_parse_keyword($raw_config_value);
        }else{
            return '{{ '.$arr[1].' }}';
        }

    }

    /**
     * @author goFrendiAsgard
     * @return array providers
     */
    public function cms_third_party_providers()
    {
        if (!in_array('curl', get_loaded_extensions())) {
            return array();
        }
        $this->load->library('Hybridauthlib');
        $providers = $this->hybridauthlib->getProviders();
        return $providers;
    }

    /**
     * @author goFrendiAsgard
     * @return array status
     * @desc return all status from third-party provider
     */
    public function cms_third_party_status()
    {
        if (!in_array('curl', get_loaded_extensions())) {
            return array();
        }
        $this->load->library('Hybridauthlib');
        $status    = array();
        $connected = $this->hybridauthlib->getConnectedProviders();
        foreach ($connected as $provider) {
            if ($this->hybridauthlib->providerEnabled($provider)) {
                $service = $this->hybridauthlib->authenticate($provider);
                if ($service->isUserConnected()) {
                    $status[$provider] = (array) $this->hybridauthlib->getAdapter($provider)->getUserProfile();
                }
            }
        }
        return $status;
    }

    /**
     * @author goFrendiAsgard
     * @return boolean success
     * @desc login/register by using third-party provider
     */
    public function cms_third_party_login($provider, $email = NULL)
    {
        // if provider not valid then exit
        $status = $this->cms_third_party_status();
        if (!isset($status[$provider]))
            return FALSE;

        $identifier = $status[$provider]['identifier'];


        $user_id = $this->cms_user_id();
        $user_id    = !isset($user_id)||is_null($user_id)?0:$user_id;
        $query   = $this->db->select('user_id')->from(cms_table_name('main_user'))->where('auth_' . $provider, $identifier)->get();
        if ($query->num_rows() > 0) { // get user_id based on auth field
            $row     = $query->row();
            $user_id = $row->user_id;
        } else { // no identifier match, register it to the database
            $third_party_email        = $status[$provider]['email'];
            $third_party_display_name = $status[$provider]['firstName'];
            
            // well, twitter sucks... it doesn't allow us to retrieve user's email
            if($third_party_email === NULL){
                $third_party_email = $email != NULL? $email : $new_user_name.'@unknown.com';
            }

            // if email match with the database, set $user_id
            if ($user_id == FALSE) {
                $query = $this->db->select('user_id')->from(cms_table_name('main_user'))->where('email', $third_party_email)->get();
                if ($query->num_rows() > 0) {
                    $row     = $query->row();
                    $user_id = $row->user_id;
                }
            }
            // if $user_id set (already_login, or $status[provider]['email'] match with database)
            if ($user_id != FALSE) {
                $data  = array(
                    'auth_' . $provider => $identifier
                );
                $where = array(
                    'user_id' => $user_id
                );
                $this->db->update(cms_table_name('main_user'), $data, $where);
            } else { // if not already login, register provider and id to the database
                $new_user_name = $third_party_display_name;

                // ensure there is no duplicate user name
                $duplicate = TRUE;
                while ($duplicate) {
                    $query = $this->db->select('user_name')->from(cms_table_name('main_user'))->where('user_name', $new_user_name)->get();
                    if ($query->num_rows() > 0) {
                        $query         = $this->db->select('user_name')->from(cms_table_name('main_user'))->get();
                        $user_count    = $query->num_rows();
                        $new_user_name = 'user_' . $user_count . ' (' . $new_user_name . ')';
                    } else {
                        $duplicate = FALSE;
                    }
                }

                // insert to database
                $data = array(
                    'user_name' => $new_user_name,
                    'email' => $third_party_email,
                    'auth_' . $provider => $identifier
                );
                $this->db->insert(cms_table_name('main_user'), $data);

                // get user_id
                $query = $this->db->select('user_id')->from(cms_table_name('main_user'))->where('email', $third_party_email)->get();
                if ($query->num_rows() > 0) {
                    $row     = $query->row();
                    $user_id = $row->user_id;
                }
            }
        }


        // set cms_user_id, cms_user_name, cms_user_email, cms_user_real_name, just as when login from the normal way
        $query = $this->db->select('user_id, user_name, email, real_name')->from(cms_table_name('main_user'))->where('user_id', $user_id)->get();
        if ($query->num_rows() > 0) {
            $row = $query->row();
            $this->cms_user_id($row->user_id);
            $this->cms_user_name($row->user_name);
            $this->cms_user_real_name($row->real_name);
            $this->cms_user_email($row->email);
            return TRUE;
        }
        return FALSE;
    }

    public final function cms_add_navigation($navigation_name, $title, $url, $authorization_id = 1, $parent_name = NULL, $index = NULL, $description = NULL, $bootstrap_glyph=NULL,
    $default_theme=NULL, $default_layout=NULL, $notif_url=NULL)
    {
        //get parent's navigation_id
        $query = $this->db->select('navigation_id, navigation_name')
            ->from(cms_table_name('main_navigation'))
            ->where('navigation_name', $parent_name)
            ->get();
        $row   = $query->row();

        $parent_id = isset($row->navigation_id) && $row->navigation_name != $navigation_name  ? $row->navigation_id : NULL;

        //if it is null, index = max index+1
        if (!isset($index)) {
            if (isset($parent_id)) {
                $whereParentId = "(parent_id = $parent_id)";
            } else {
                $whereParentId = "(parent_id IS NULL)";
            }
            $query = $this->db->select_max('index')
                ->from(cms_table_name('main_navigation'))
                ->where($whereParentId)
                ->get();
            if ($query->num_rows() > 0) {
                $row   = $query->row();
                $index = $row->index+1;
            }
            if (!isset($index))
                $index = 0;
        }

        // is there any navigation with the same name?
        $dont_insert = FALSE;
        $query = $this->db->select('navigation_id')->from(cms_table_name('main_navigation'))
            ->where('navigation_name', $navigation_name)->get();
        if($query->num_rows()>0){
            $dont_insert = TRUE;
            $row = $query->row();
            $navigation_id = $row->navigation_id;
        }else{
            // is there any navigation with same url
            $query = $this->db->select('navigation_id')->from(cms_table_name('main_navigation'))
                ->where('url', $url)->get();
            if($query->num_rows()>0){
                throw('Navigation with the same url already exists');
                return NULL;
            }
        }
        
        $data = array(
            "navigation_name" => $navigation_name,
            "title" => $title,
            "url" => $url,
            "authorization_id" => $authorization_id,
            "index" => $index,
            "description" => $description,
            "active"=>1,
            "bootstrap_glyph"=>$bootstrap_glyph,
            "default_theme"=>$default_theme,
            "default_layout"=>$default_layout,
            "notif_url"=>$notif_url,
        );
        if (isset($parent_id)) {
            $data['parent_id'] = $parent_id;
        }

        //insert it :D
        if($dont_insert){
            unset($data['index']);
            $this->db->update(cms_table_name('main_navigation'), $data, array('navigation_id'=>$navigation_id));
        }else{
            $this->db->insert(cms_table_name('main_navigation'), $data);
        }
    }
    public final function cms_remove_navigation($navigation_name)
    {
        //get navigation_id
        $query = $this->db->select('navigation_id')
            ->from(cms_table_name('main_navigation'))
            ->where('navigation_name', $navigation_name)
            ->get();
        if ($query->num_rows() > 0) {
            $row           = $query->row();
            $navigation_id = isset($row->navigation_id) ? $row->navigation_id : NULL;
        }

        if (isset($navigation_id)) {
            //delete quicklink
            $where = array(
                "navigation_id" => $navigation_id
            );
            $this->db->delete(cms_table_name('main_quicklink'), $where);
            //delete cms_group_navigation
            $where = array(
                "navigation_id" => $navigation_id
            );
            $this->db->delete(cms_table_name('main_group_navigation'), $where);
            //delete cms_navigation
            $where = array(
                "navigation_id" => $navigation_id
            );
            $this->db->delete(cms_table_name('main_navigation'), $where);
        }
    }
    public final function cms_add_privilege($privilege_name, $title, $authorization_id = 1, $description = NULL)
    {
        $data = array(
            "privilege_name" => $privilege_name,
            "title" => $title,
            "authorization_id" => $authorization_id,
            "description" => $description
        );
        $query = $this->db->select('privilege_id')
            ->from(cms_table_name('main_privilege'))
            ->where('privilege_name', $privilege_name)
            ->get();
        if($query->num_rows()>0){
            $row = $query->row();
            $privilege_id = $row->privilege_id;
            $this->db->update(cms_table_name('main_privilege'), $data, array('privilege_id'=>$privilege_id));
        }else{
            $this->db->insert(cms_table_name('main_privilege'), $data);
        }
    }
    public final function cms_remove_privilege($privilege_name)
    {
        $query = $this->db->select('privilege_id')
            ->from(cms_table_name('main_privilege'))
            ->where('privilege_name', $privilege_name)
            ->get();

        if ($query->num_rows() > 0) {
            $row = $query->row();
            $privilege_id = $row->privilege_id;
        }

        if (isset($privilege_id)) {
            //delete cms_group_privilege
            $where = array(
                "privilege_id" => $privilege_id
            );
            $this->db->delete(cms_table_name('main_group_privilege'), $where);
            //delete cms_privilege
            $where = array(
                "privilege_id" => $privilege_id
            );
            $this->db->delete(cms_table_name('main_privilege'), $where);
        }
    }

    public final function cms_add_group($group_name, $description){
        $data = array(
            "group_name" => $group_name,
            "description" => $description
        );
        $query = $this->db->select('group_id')
            ->from(cms_table_name('main_group'))
            ->where('group_name', $group_name)
            ->get();
        if($query->num_rows()>0){
            $row = $query->row();
            $group_id = $row->group_id;
            $this->db->update(cms_table_name('main_group'), $data, array('group_id'=>$group_id));
        }else{
            $this->db->insert(cms_table_name('main_group'), $data);
        }
    }
    public final function cms_remove_group($group_name)
    {
        $query = $this->db->select('group_id')
            ->from(cms_table_name('main_group'))
            ->where('group_name', $group_name)
            ->get();

        if ($query->num_rows() > 0) {
            $row = $query->row();
            $group_id = $row->group_id;
        }

        if (isset($group_id)) {
            //delete cms_group_privilege
            $where = array(
                "group_id" => $group_id
            );
            $this->db->delete(cms_table_name('main_group_user'), $where);
            //delete cms_privilege
            $where = array(
                "privilege_id" => $privilege_id
            );
            $this->db->delete(cms_table_name('main_group'), $where);
        }
    }

    public final function cms_add_widget($widget_name, $title=NULL, $authorization_id = 1, $url = NULL, $slug = NULL, $index = NULL, $description = NULL)
    {
        //if it is null, index = max index+1
        if (!isset($index)) {
            if (isset($slug)) {
                $whereSlug = "(slug = '".addslashes($slug)."')";
            } else {
                $whereSlug = "(slug IS NULL)";
            }
            $query = $this->db->select_max('index')
                ->from(cms_table_name('main_widget'))
                ->where($whereSlug)
                ->get();
            if ($query->num_rows() > 0) {
                $row   = $query->row();
                $index = $row->index+1;
            }

            if (!isset($index))
                $index = 0;
        }

        $data = array(
            "widget_name" => $widget_name,
            "title" => $title,
            "slug" => $slug,
            "index" => $index,
            "authorization_id" => $authorization_id,
            "url" => $url,
            "description" => $description
        );
        $query = $this->db->select('widget_id')
            ->from(cms_table_name('main_widget'))
            ->where('widget_name', $widget_name)
            ->get();
        if($query->num_rows()>0){
            $row = $query->row();
            $widget_id = $row->widget_id;
            unset($data['index']);
            $this->db->update(cms_table_name('main_widget'), $data, array('widget_id'=>$widget_id));
        }else{
            $this->db->insert(cms_table_name('main_widget'), $data);
        }
    }

    public final function cms_remove_widget($widget_name)
    {
        $query = $this->db->select('widget_id')
            ->from(cms_table_name('main_widget'))
            ->where('widget_name', $widget_name)
            ->get();
        if($query->num_rows()>0){
            $row       = $query->row();
            $widget_id = $row->widget_id;

            if (isset($widget_id)) {
                //delete cms_group_privilege
                $where = array(
                    "widget_id" => $widget_id
                );
                $this->db->delete(cms_table_name('main_group_widget'), $where);
                //delete cms_privilege
                $where = array(
                    "widget_id" => $widget_id
                );
                $this->db->delete(cms_table_name('main_widget'), $where);
            }

        }

    }

    public final function cms_add_quicklink($navigation_name)
    {
        $query = $this->db->select('navigation_id')
            ->from(cms_table_name('main_navigation'))
            ->where('navigation_name', $navigation_name)
            ->get();
        if ($query->num_rows() > 0) {
            $row           = $query->row();
            $navigation_id = $row->navigation_id;
            // index = max index+1
            $query = $this->db->select_max('index')
                ->from(cms_table_name('main_quicklink'))
                ->get();
            $row           = $query->row();
            $index         = $row->index+1;
            if (!isset($index))
                $index = 0;

            // insert
            $data = array(
                "navigation_id" => $navigation_id,
                "index" => $index
            );
            $query = $this->db->select('navigation_id')
                ->from(cms_table_name('main_quicklink'))
                ->where('navigation_id', $navigation_id)
                ->get();
            if($query->num_rows()==0){
                $this->db->insert(cms_table_name('main_quicklink'), $data);
            }
        }
    }

    public final function cms_remove_quicklink($navigation_name)
    {
        $SQL   = "SELECT navigation_id FROM ".cms_table_name('main_navigation')." WHERE navigation_name ='" . addslashes($navigation_name) . "'";
        $query = $this->db->query($SQL);
        if ($query->num_rows() > 0) {
            $row           = $query->row();
            $navigation_id = $row->navigation_id;

            // delete
            $where = array(
                "navigation_id" => $navigation_id
            );
            $this->db->delete(cms_table_name('main_quicklink'), $where);
        }
    }

    public final function cms_assign_navigation($navigation_name, $group_name){
        $query = $this->db->select('group_id')
            ->from(cms_table_name('main_group'))
            ->where('group_name', $group_name)
            ->get();
        if($query->num_rows()>0){
            $row = $query->row();
            $group_id = $row->group_id;

            $query = $this->db->select('navigation_id')
                ->from(cms_table_name('main_navigation'))
                ->where('navigation_name', $navigation_name)
                ->get();
            if($query->num_rows()>0){
                $row = $query->row();
                $navigation_id = $row->navigation_id;
                $query = $this->db->select('group_id')
                    ->from(cms_table_name('main_group_navigation'))
                    ->where('navigation_id', $navigation_id)
                    ->where('group_id', $group_id)
                    ->get();
                if($query->num_rows()==0){
                    $this->db->insert(cms_table_name('main_group_navigation'), array(
                        'navigation_id' => $navigation_id,
                        'group_id' => $group_id));
                }
            }

        }
    }
    public final function cms_assign_privilege($privilege_name, $group_name){
        $query = $this->db->select('group_id')
            ->from(cms_table_name('main_group'))
            ->where('group_name', $group_name)
            ->get();
        if($query->num_rows()>0){
            $row = $query->row();
            $group_id = $row->group_id;

            $query = $this->db->select('privilege_id')
                ->from(cms_table_name('main_privilege'))
                ->where('privilege_name', $privilege_name)
                ->get();
            if($query->num_rows()>0){
                $row = $query->row();
                $privilege_id = $row->privilege_id;
                $query = $this->db->select('group_id')
                    ->from(cms_table_name('main_group_privilege'))
                    ->where('privilege_id', $privilege_id)
                    ->where('group_id', $group_id)
                    ->get();
                if($query->num_rows()==0){
                    $this->db->insert(cms_table_name('main_group_privilege'), array(
                        'privilege_id' => $privilege_id,
                        'group_id' => $group_id));
                }
            }

        }
    }
    public final function cms_assign_widget($widget_name, $group_name){
        $query = $this->db->select('group_id')
            ->from(cms_table_name('main_group'))
            ->where('group_name', $group_name)
            ->get();
        if($query->num_rows()>0){
            $row = $query->row();
            $group_id = $row->group_id;

            $query = $this->db->select('widget_id')
                ->from(cms_table_name('main_widget'))
                ->where('widget_name', $widget_name)
                ->get();
            if($query->num_rows()>0){
                $row = $query->row();
                $widget_id = $row->widget_id;
                $query = $this->db->select('group_id')
                    ->from(cms_table_name('main_group_widget'))
                    ->where('widget_id', $widget_id)
                    ->where('group_id', $group_id)
                    ->get();
                if($query->num_rows()==0){
                    $this->db->insert(cms_table_name('main_group_widget'), array(
                        'widget_id' => $widget_id,
                        'group_id' => $group_id));
                }
            }

        }
    }

    public final function cms_execute_sql($SQL, $separator)
    {
        $queries = explode($separator, $SQL);
        foreach ($queries as $query) {
            if(trim($query) == '') continue;
            $table_prefix = cms_module_table_prefix($this->cms_module_path());
            $module_prefix = cms_module_prefix($this->cms_module_path());
            $query = preg_replace('/\{\{ complete_table_name:(.*) \}\}/si', $table_prefix==''? '$1': $table_prefix.'_'.'$1', $query);
            $query = preg_replace('/\{\{ module_prefix \}\}/si', $module_prefix, $query);
            $this->db->query($query);
        }
    }

    public function cms_set_editing_mode(){
        $this->session->set_userdata('__cms_editing_mode', TRUE);
    }

    public function cms_unset_editing_mode(){
        $this->session->set_userdata('__cms_editing_mode', FALSE);
    }

    public function cms_editing_mode(){
        return $this->session->userdata('__cms_editing_mode') === TRUE;
    }

}