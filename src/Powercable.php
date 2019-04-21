<?php
/**
 * Powercable - 1) Uses the current time/date (or a provided startdate) and the
 * endtime (this should be provided) to work out the work days between the start date and
 * the end date. Also, similarly works backwards from end date to give the latest possible
 * start date.
 *
 * Created by PhpStorm.
 * User: Neil
 * Date: 11/02/2019
 * Time: 16:23
 */

namespace Shortdark;

class Powercable {
    /********************
     ** CONFIG
     ********************/
    /*
     * Integer
     * Maximum number of working days we may need
     * i.e. we're going to calculate values from 1 day, all the way up to n days
     */
    public $n=4;

    /*
     * Integer (24 hour clock)
     * When do we want the cutoff time to be?
     */
    public $cutoff = 12; // When is the cutoff time for both one and two day items? (24 hour clock), integer (just the hour)

    /*
     * Bank Holiday JSON files...
     */
    public $json_url = 'https://www.gov.uk/bank-holidays.json'; // JSON API
    private $local_json = './bank-holidays.json'; // local copy of JSON API

    /********************
     ** VARIABLES
     ********************/

    /*
     * Unix timestamp
     * Should be provided in most use cases
     */
    public $endtime;

    /*
     * Unix timestamp
     * This is either provided, or we just use the current date/time
     * If this is after the "cutoff" or if it is a weekend or bank holiday,
     * this value get modified to be the actual first "work day".
     */
    public $starttime;
    public $cutoff_string = ''; // This is the human-readable cutoff time for the tribute, string
    public $after_cutoff; // boolean

    /*
     * Arrays used to calculate stuff
     * Some of these may be useful in their own right, for example,
     * the array of bank holidays from the gov.uk API may be useful elsewhere
     */
    public $imageworkdays = []; // array of timestamps
    public $workdays = []; // array of timestamps
    public $bankhols = []; // array of "Y-m-d" dates
    public $products_for_timescale = []; // array of strings

    /*
     * Arrays
     * These are the outputs...
     */
    public $outcome_message = []; // array of strings
    public $workdays_boolean = []; // array of booleans
    public $image_message = []; // array of strings
    
    /********************
     ** PUBLIC FUNCTIONS
     ********************/

    /**
     * Working backwards from the end date, are we able to process something in n days from now?
     *
     * Output: $this->image_message
     */
    public function latestStartTime(){
        $this->getBankHolidays();
        $this->humanReadableCutoff();
        $this->populateProductTimeArray();
        $this->outcomeMessages();

        // Make sure we have the end date as 0 hours
        $this->endtime = $this->resetDateToMidnight($this->endtime);
        // Work backwards from the end date to get the time the users need to upload by
        $this->calculateWorkingDates();
        if( !empty($this->imageworkdays) && 1<(int)$this->endtime ){
            // Create the messages to put on the upload page
            $this->populateImageMessages();
        }
    }

    /**
     * Getting the acceptable dates can be done with only today's date and the number of days required, $n
     * Calculating the boolean requires the end time to be set
     *
     * E.g. when someone is allowed to order something, needs one more day than processing
     *
     * Output: $this->workdays_boolean
     */
    public function earliestEndTime(){
        $this->getBankHolidays();
        $this->humanReadableCutoff();
        $this->populateProductTimeArray();
        $this->outcomeMessages();

        // Calculate when the first work day will be
        $this->calculateWorkStartDate();
        // start date set to 0 hours
        $this->starttime = $this->resetDateToMidnight($this->starttime);
        // service date set to 0 hours
        $this->endtime = $this->resetDateToMidnight($this->endtime);
        // Calculate an array of the next "n" working days
        $this->calculateEarliestEndDates();
        if( !empty($this->workdays) && 1<(int)$this->endtime ){
            $this->calculateBooleans();
        }
    }

    /********************
     ** PRIVATE FUNCTIONS
     ********************/

    /**
     * Calculate the start date for the one or two days work...
     */
    private function calculateWorkStartDate(){
        // If we have forgotten to add the start date or it is not set, add it here
        $this->isStartDateSet();
        // We want to check what time it is, are we after the cutoff or not?
        $this->isItAfterCutoff();
        // Are we after the cutoff or on a bank holiday or weekend?
        // If so start date is the next working day...
        $ymddate = date('Y-m-d', $this->starttime);
        if( false !== $this->after_cutoff || false !== $this->isThisDateAWeekend($this->starttime) || false !== $this->isThisDateABankHoliday($ymddate) ){
            $this->starttime = $this->nextWorkingDayTimestamp($this->starttime);
        }
    }

    /**
     * If the start date is not set, use today (now)
     */
    private function isStartDateSet(){
        if(!isset($this->starttime) || ''==$this->starttime ){
            // This method of getting the unix timestamp for now uses the current time
            // To get the timestamp for midnight, or a specific time use mktime()
            $this->starttime = date('U');
        }
    }

    /**
     * @param string $datetimestamp
     * @return false|int|string
     */
    private function resetDateToMidnight($datetimestamp=''){
        if(isset($datetimestamp)){
            $day = date('j',$datetimestamp);
            $month = date('n',$datetimestamp);
            $year = date('Y',$datetimestamp);
            $datetimestamp = mktime(0,0,0,$month, $day, $year);
        }
        return $datetimestamp;
    }

    /**
     * Is the current time ($startdate) after the cutoff time?
     */
    private function isItAfterCutoff(){
        $this->after_cutoff = false;
        if($this->cutoff <= date('G', $this->starttime)){
            $this->after_cutoff = true;
        }
    }

    /**
     * Make the array with the dates needed for 1 day lead time, 2 days, etc
     * This checks for bank holidays and weekends as it goes
     */
    private function calculateWorkingDates(){
        $this->imageworkdays[0] = $this->endtime;
        $temp = $this->endtime;
        for($j=1;$j<=$this->n;$j++){
            $this->imageworkdays[$j] = $this->prevWorkingDayTimestamp($temp);
            $temp = $this->imageworkdays[$j];
        }
    }

    /**
     * What day is it? How early can the service be?
     */
    private function calculateEarliestEndDates(){
        $this->workdays[0] = $this->starttime;
        $temp = $this->starttime;
        for($j=1;$j<=$this->n;$j++){
            $this->workdays[$j] = $this->nextWorkingDayTimestamp($temp);
            $temp = $this->workdays[$j];
        }
    }

    /**
     * Check whether a date is on a weekend or not
     *
     * @param string $datetimestamp
     * @return bool
     */
    private function isThisDateAWeekend($datetimestamp=''){
        if(isset($datetimestamp)){
            $testdate_day = date('N',$datetimestamp);
        }else{
            $testdate_day = date('N');
        }
        if(5 < $testdate_day){
            return true;
        }
        return false;
    }

    /**
     * Check a date to see if it's a bank holiday
     * Date format YYYY-mm-dd
     * Returns true if it is a holiday
     *
     * @param string $date
     * @return bool
     */
    private function isThisDateABankHoliday($date=""){
        $a=0;
        if($this->bankhols[$a]){
            while(isset($this->bankhols[$a])){
                if($date == $this->bankhols[$a]){
                    return true;
                }
                $a++;
            }
        }
        return false;
    }

    /**
     * Convert a timestamp into the previous work day (not weekend or bank holiday)
     *
     * @param string $datetimestamp
     * @return false|int|string
     */
    private function prevWorkingDayTimestamp($datetimestamp=''){
        // Add one day to the date
        $datetimestamp =  strtotime("-1 day", $datetimestamp);
        // Cannot be a weekend
        if(5 < date('N', $datetimestamp)){
            while(6 <= date('N', $datetimestamp)){
                // The next working day is not going to be a weekend
                // Keep adding a day until we get to monday...
                $datetimestamp =  strtotime("-1 day", $datetimestamp);
            }
        }
        // This day should not be a weekend, make sure it's not a bank holiday either
        $ymddate = date('Y-m-d', $datetimestamp);
        $bh = $this->isThisDateABankHoliday($ymddate);
        if($bh !== false){
            // If it is a bank holiday, we need to add another day
            // and check once again that it's not a bank holiday
            // so run the function again...
            $datetimestamp = $this->prevWorkingDayTimestamp($datetimestamp);
        }
        return $datetimestamp;
    }

    /**
     * Change a timestamp into the next working day (not bank holidays or weekends)
     *
     * @param string $datetimestamp
     * @return false|int|string
     */
    private function nextWorkingDayTimestamp($datetimestamp=''){
        // Add one day to the date
        $datetimestamp =  strtotime("+1 day", $datetimestamp);
        // Cannot be a weekend
        if(5 < date('N', $datetimestamp)){
            while(6 <= date('N', $datetimestamp)){
                // The next working day is not going to be a weekend
                // Keep adding a day until we get to monday...
                $datetimestamp =  strtotime("+1 day", $datetimestamp);
            }
        }
        // This day should not be a weekend, make sure it's not a bank holiday either
        $test = date('Y-m-d', $datetimestamp);
        $bh = $this->isThisDateABankHoliday($test);
        if($bh !== false){
            // If it is a bank holiday, we need to add another day
            // and check once again that it's not a bank holiday
            // so run the function again...
            $datetimestamp = $this->nextWorkingDayTimestamp($datetimestamp);
        }
        return $datetimestamp;
    }

    /**
     * Get the bank holidays from the Government API
     * The government API should get updated yearly,
     * otherwise we can make a JSON manually at the start of each year
     * if the government changes/stops this service
     */
    private function getBankHolidays(){
        $data = file_get_contents($this->json_url);
        // Grab the data locally, if possible...
        // $data = file_get_contents($this->local_json);
        $hols = json_decode($data, true);
        // Get all bank holidays in England this year and next year
        foreach ($hols['england-and-wales']['events'] as $hol){
            if(date('Y') == substr($hol['date'], 0,4) || date('Y', strtotime('+1 year')) == substr($hol['date'], 0,4)){
                $this->bankhols[] = $hol['date'];
            }
        }
    }

    /**
     * This boolean is whether the work day is in time for the end date
     * False is too late, i.e. can't be done
     * True means that for $j number of days lead time there is enough time
     */
    private function calculateBooleans(){
        if( isset($this->endtime) ){
            $j=1;
            while(isset($this->workdays[$j])){
                // The time for the servicetime and the workday should both be midnight.
                if($this->endtime > $this->workdays[$j]){
                    $this->workdays_boolean[$j] = true;
                }else{
                    $this->workdays_boolean[$j] = false;
                }
                $j++;
            }
        }
    }

    /**
     * Generate the array for the products for each array item
     */
    private function populateProductTimeArray(){
        // These string go straight into $this->output
        $this->products_for_timescale[1] = "one-day product";
        $this->products_for_timescale[2] = "two-day product";
        // for safety let's make the default "product"
        $this->products_for_timescale[3] = "product";
        $this->products_for_timescale[4] = "product";
        $this->products_for_timescale[5] = "product";
    }

    /**
     * Using the dates we've calculated, make the messages that say when the cutoff times are for 1 day, 2 days, etc.
     * This is for the family upload image page.
     */
    private function populateImageMessages(){
        $j=1;
        while( $j <= $this->n ){
            $date = date('l jS F, Y',$this->imageworkdays[$j]);
            $this->image_message[$j] = "Work should be submitted before $this->cutoff_string on $date.";
            $j++;
        }
    }

    /**
     * The warning/error message on the order page
     */
    private function outcomeMessages(){
        $j=1;
        $day_string = "day";
        while( isset($this->products_for_timescale[$j]) ){
            if(1 < $j){
                $day_string = "days";
            }
            $this->outcome_message[$j] = "<p>This {$this->products_for_timescale[$j]} should be ordered before $this->cutoff_string $j working $day_string clear of the end.</p>";
            $j++;
        }
    }

    /**
     * Converts the 24-hour cutoff integer into a human-readable format
     */
    private function humanReadableCutoff(){
        if(12 <= $this->cutoff){
            if(12 < $this->cutoff){
                $humancutofftime = $this->cutoff - 12;
            }else{
                $humancutofftime = $this->cutoff;
            }
            $cutoff_meridiem = 'PM';
        }else{
            $humancutofftime = $this->cutoff;
            $cutoff_meridiem = 'AM';
        }
        $this->cutoff_string = $humancutofftime . $cutoff_meridiem;
    }

}
