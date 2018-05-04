<?php

namespace Instructor;

use DBAL\Database;
use GoogleMapsGeocoder;
use UserAuth\User;

class Instructor extends User{
    protected $db;
    protected $status = array(0 => 'Pending', 1 => 'Active', 2 => 'Disabled', 3 => 'Suspended', 4 => 'Delisted');
    
    public $instructor_table = 'instructors';
    public $testimonial_table = 'testimonials';
    
    public $priority_period = '3 months';
    
    public $display_testimonials = false;
    
    protected $apiKey;
    
    /**
     * Constructor
     * @param Database $db This should be an instance of the database class
     */
    public function __construct(Database $db) {
        parent::__construct($db);
        $this->table_users = $this->instructor_table;
        $this->table_attempts = $this->instructor_table.'_attempts';
        $this->table_requests = $this->instructor_table.'_requests';
        $this->table_sessions = $this->instructor_table.'_sessions';
        $this->removePriorities();
    }
    
    /**
     * Sets the API Key for the Google Geocoding Object
     * @param string $key This should be your Google API Key
     * @return $this
     */
    public function setAPIKey($key){
        $this->apiKey = $key;
        return $this;
    }
    
    /**
     * Gets the Google API key if set
     * @return string|false If the API key is set it will be returned else will return false
     */
    public function getAPIKey(){
        if(is_string($this->apiKey)){
            return $this->apiKey;
        }
        return false;
    }
    
    /**
     * Returns the status text for the given status number
     * @param int $status This should be the status number you wish to get the test for
     * @return string|false This will be the status text if key exists else will be false
     */
    public function instructorStatus($status){
        if(array_key_exists($status, $this->status)){
            return $this->status[intval($status)];
        }
        return false;
    }
    
    /**
     * Get a list of all of the instructors 
     * @param inst $active If set to a number should be the active value else should be set to false for all instructors
     * @return array|false Should return an array of all existing instructors or if no values exist will return false
     */
    public function getAllInstructors($active = 1){
        return $this->db->selectAll($this->instructor_table, array('active' => intval($active)), '*', array('fino' => 'DESC'));
    }
    
    /**
     * Returns the information for a individual driving instructor
     * @param int $fino This should be the franchise number of the instructor
     * @return array|false This should be an array of the instructor information if the fino exists else will be false
     */
    public function getInstructorInfo($fino){
        return $this->db->select($this->instructor_table, array('fino' => intval($fino)));
    }
    
    /**
     * Add a new instructor to the database
     * @param int $fino This should be the instructors unique franchise number
     * @param string $name The instructors name
     * @param string $email The email address to associate with the instructor
     * @param string $domain The website that this instructor has been assigned
     * @param string $gender The instructors gender set to either M or F
     * @param string $password The password that the instructor has been assigned
     * @param array $additionalInfo Any additional information can be added to this as an array
     * @return boolean If the information has been successfully added will return true else will return false
     */
    public function addInstructor($fino, $name, $email, $domain, $gender, $password, $additionalInfo = []){
        if(!$this->getInstructorInfo($fino) && is_numeric($fino) && is_array($additionalInfo) && filter_var($email, FILTER_VALIDATE_EMAIL)){
            if(isset($additionalInfo['about']) && empty(trim($additionalInfo['about']))){$additionalInfo['about'] = NULL;}
            if(isset($additionalInfo['offers']) && empty(trim($additionalInfo['offers']))){$additionalInfo['offers'] = NULL;}
            return $this->db->insert($this->instructor_table, array_merge(array('fino' => intval($fino), 'name' => $name, 'gender' => $gender, 'email' => $email, 'website' => $domain, 'password' => $this->getHash($password), 'password_base' => base64_encode($password)), $additionalInfo));
        }
        return false;
    }
    
    /**
     * Updates instructor information
     * @param int $fino This should be the franchise number of the instructor you are updating
     * @param array $information This should be an array of all of the information you are updating in the format or array('field' => 'value', 'fields2' => 2)
     * @return boolean If the information is successfully updated will return true else returns false
     */
    public function updateInstructor($fino, $information = []){
        if(empty(trim($information['about']))){$information['about'] = NULL;}
        if(empty(trim($information['offers']))){$information['offers'] = NULL;}
        if(empty(trim($information['notes']))){$information['notes'] = NULL;}
        return $this->db->update($this->instructor_table, $information, array('fino' => $fino));
    }
    
    /**
     * Updates the instructors personal information in the database
     * @param int $fino This should be franchise number for the instructor
     * @param array $information This should be any information that you are updating in an array
     * @return boolean If the information is updated will return true else will return false
     */
    public function updateInstructorPersonalInformation($fino, $information = []){
        foreach($information as $info => $value){
            if(empty(trim($value))){$information[$info] = NULL;}
        }
        return $this->db->update($this->instructor_table, $information, array('fino' => $fino));
    }
    
    /**
     * Update the latitude and longitude where the instructor is located
     * @param int $fino This should be the franchise number of the instructor you are updating
     * @param string $postcode This should be the postcode where the instructor is located
     * @return boolean If the information is updated will return true else returns false
     */
    public function updateInstructorLocation($fino, $postcode){
        $maps = new GoogleMapsGeocoder($postcode.', UK');
        if($this->getAPIKey() !== false){$maps->setApiKey($this->getAPIKey());}
        $maps->geocode();
        if($maps->getLatitude()){
            return $this->db->update($this->instructor_table, array('lat' => $maps->getLatitude(), 'lng' => $maps->getLongitude()), array('fino' => $fino));
        }
        return false;
    }
    
    /**
     * Gets a list of all of the instructors matching the given criteria
     * @param array $where This should be the criteria that the database query needs to match
     * @param int $limit This should be the maximum number of instructors to display
     * @param boolean $active If you only wish to retrieve the active instructors set this to true else for all instructors set to false
     * @return array|false Will return a list of instructors if any match the criteria else will return false
     */
    public function getInstructors($where, $limit = 50, $active = true){
        if($active === true){
            $where['active'] = 1;
        }
        return $this->listInstructors($this->db->selectAll($this->instructor_table, $where, '*', array('priority' => 'DESC', 'RAND()'), $limit));
    }
    
    /**
     * Find the closest instructors to the given postcode
     * @param string $postcode This should be the postcode that you wish to find the closest instructor to
     * @param int $limit The maximum number of instructors to display
     * @param boolean $cover If the search is only postcodes set this to true to only display instructors who have this listed as an area they cover
     * @param boolean $hasOffer If you want to prioritise those with an offer first set this to true
     * @return array|boolean If any instructors exist they will be returned as an array else will return false
     */
    public function findClosestInstructors($postcode, $limit = 50, $cover = true, $hasOffer = false){
        $maps = new GoogleMapsGeocoder($postcode.', UK', 'xml');
        if($this->getAPIKey() !== false){$maps->setApiKey($this->getAPIKey());}
        $maps->geocode();
        if($maps->getLatitude()){
            if($cover === true || preg_match('/([A-Z]\S\d?\d)/', $this->smallPostcode($postcode)) === true){
                $coverSQL = " AND `postcodes` LIKE '%,".$this->smallPostcode($postcode).",%'";
                $distance = 100;
            }
            else{
                $coverSQL = "";
                $distance = 15;
            }
            return $this->listInstructors($this->db->query("SELECT *, (3959 * acos(cos(radians('{$maps->getLatitude()}')) * cos(radians(lat)) * cos(radians(lng) - radians('{$maps->getLongitude()}')) + sin(radians('{$maps->getLatitude()}')) * sin(radians(lat)))) AS `distance` FROM `{$this->instructor_table}` WHERE `active` = 1{$coverSQL} HAVING `distance` < {$distance} ORDER BY".($hasOffer !== false ? " `offer` DESC," : "")." `priority` DESC, `distance` ASC LIMIT {$limit};"));
        }
        return $this->findInstructorsByPostcode($postcode, $limit, $hasOffer);
    }
    
    /**
     * Returns a list of instructors covering a given postcode area
     * @param string $postcode This should be the postcode area
     * @param int $limit The maximum number of instructors to display
     * @param boolean $hasOffer If you want to prioritise those with an offer first set this to true
     * @return array|false If any instructors exist they will be returned as an array else will return false
     */
    public function findInstructorsByPostcode($postcode, $limit = 50, $hasOffer = false){
        return $this->listInstructors($this->db->query("SELECT * FROM `{$this->instructor_table}` WHERE `active` = 1 AND `postcodes` LIKE '%,".$this->smallPostcode($postcode).",%' ORDER BY".($hasOffer !== false ? " `offer` DESC," : "")." `priority` DESC, RAND() LIMIT {$limit};"));
    }
    
    /**
     * Find the closest instructors with those who have offers prioritised first
     * @param string $postcode This should be the postcode that you wish to find the closest instructor to
     * @param int $limit The maximum number of instructors to display
     * @param boolean $cover If the search is only postcodes set this to true to only display instructors who have this listed as an area they cover
     * @return array|boolean If any instructors exist they will be returned as an array else will return false
     */
    public function findClosestInstructorWithOffer($postcode, $limit = 50, $cover = true){
        return $this->findClosestInstructors($postcode, $limit, $cover, true);
    }
    
    /**
     * List of all of the instructors and get additional variables
     * @param array $instructors An array of the instructors results from the database so additional information can be retrieved and added
     * @return array|false If any instructors are returned their information will be returned else if none exists will return false
     */
    protected function listInstructors($instructors){
        if(is_array($instructors)){
            foreach($instructors as $i => $instructor){
                $instructors[$i]['postcodes'] = $this->instPostcodes($instructor['postcodes']);
                $instructors[$i]['firstname'] = $this->firstname($instructor['name']);
                $instructors[$i]['testimonials'] = $this->instTestimonials($instructor['fino']);
            }
            return $instructors;
        }
        return false;
    }
    
    /**
     * Add priority to a given instructor
     * @param int $fino This should be the instructors unique franchise number
     * @return boolean If the record is updated will return true else returns false
     */
    public function addPriority($fino){
        if(is_numeric($fino)){
            $date = new \DateTime();
            return $this->db->update($this->instructor_table, array('priority' => 1, 'priority_start_date' => $date->format('Y-m-d H:i:s')), array('fino' => intval($fino)));
        }
        return false;
    }

    /**
     * Remove anyone from the priority list who has been there for longer than the alloted period
     */
    public function removePriorities(){
        $date = new \DateTime();
        $date->modify("-{$this->priority_period}");
        $this->db->update($this->instructor_table, array('priority' => 0, 'priority_start_date' => NULL), array('priority' => 1, 'priority_start_date' => array('<=', $date->format('Y-m-d H:i:s'))));
    }
    
    /**
     * Return any instructor testimonials in a random order
     * @param int $fino This should be the instructors unique franchise number
     * @param int $limit The maximum number of testimonials to show
     * @return array|false If any testimonials exist they will be returned as an array else will return false
     */
    public function instTestimonials($fino, $limit = 5){
        if($this->display_testimonials === true){
            return $this->db->selectAll($this->testimonial_table, array('fino' => intval($fino)), '*', 'RAND()', intval($limit));
        }
        return false;
    }
    
    /**
     * Return only the first name for the instructor
     * @param string $name The full name for the instructor
     * @return string Will return only the first name
     */
    protected function firstname($name){
        $names = explode(' ', $name);
        return $names[0];
    }
    
    /**
     * Format the postcode string for viewing
     * @param string $postcodes The list of postcodes that the instructor covers
     * @return string A formated list will be returned to make it more easily readable
     */
    protected function instPostcodes($postcodes){
        return str_replace(',', ', ', substr($postcodes, 1, -1));
    }
    
    /**
     * Returns only the first part of a given postcode
     * @param string $postcode This should be the give postcode to convert to the small postcode
     * @param boolean $alpha If you only want the alpha characters and not any numeric set this to true
     * @return string The small postcode will be returned
     */
    protected function smallPostcode($postcode, $alpha = false){
        $pcode = $this->replaceIncorrectNumbers($postcode);
        $length = strlen($pcode);

        if($length >= 5){
            $smallpcode = substr($pcode, 0, $length - 3);
        }
        else{
            $smallpcode = $pcode;
        }
        if($alpha !== false){$smallpcode = preg_replace('/[^A-Za-z_]/', '', $smallpcode);}

        return strtoupper($smallpcode);	
    }
    
    /**
     * Replace special characters with the corresponding number on the keyboard
     * @param string $string This should be the string where incorrect values will be replaced
     * @return string The correctly formatted string will be returned
     */
    protected function replaceIncorrectNumbers($string){
        $characters = array('!', '"', '£', '$', '%', '^', '&', '*', '(', ')', ' ');
        $numbers = array(1, 2, 3, 4, 5, 6, 7, 8, 9, 0, '');
        return str_replace($characters, $numbers, trim($string));
    }
}
