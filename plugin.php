<?php
/* 
Plugin Name: JDesrosiers Google Calendar
Plugin URI: 
Description: A plugin that integrate with Google Calendars to display a list of events via shortcodes and template tags
Author: Julien Desrosiers
Version: 1.0 
Author URI: http://www.juliendesrosiers.com
*/  

// (Most of the code is taken from: https://raw.github.com/media-uk/GCalPHP )



// Takes:
// - $config : Hash of the call's configurations 
// - $calendar_xml_address : String of the calendar xml address
// 
// Returns a SimpleXML object
function jdgc_get_calendar_xml($config, $calendar_xml_address) {
  if ($config['use_cache']) {
    $cache_time = 3600*12; // 12 hours
    $wp_content_directory = realpath(dirname(__FILE__) . '/tmp/');
    $cache_file = $wp_content_directory.'/jdgc_cache.xml'; //xml file saved on server
   
    $timedif = @(time() - filemtime($cache_file));

    $xml = "";
    if (file_exists($cache_file) && $timedif < $cache_time) {
      $str = file_get_contents($cache_file);
      $xml = simplexml_load_string($str);
    } else { //not here
      $xml = simplexml_load_file($calendar_xml_address); //come here
      if ($f = fopen($cache_file, 'w')) { //save info
        $str = $xml->asXML();
        fwrite ($f, $str, strlen($str));
        fclose($f);
      } else { $o .= "<P>Can't write to the cache.</P>"; }
    }
   
  } else { // no caching
    $xml = simplexml_load_file($calendar_xml_address);
  }
   
  return $xml;
}


// Returns the calendar HTML
function jdgc_get_calendar($settings) {

  // Configurations:
  //
  $config = array_merge(array(
    'feed_url' => null,
    'items_to_show' => 10,
    'date_format' => 'j M', // 10 Mar - see http://www.php.net/date for details
    'time_format' => 'g.ia', // 12.15am

    // The timezone that your user/venue is in 
    // (i.e. the time you're entering stuff in Google Calendar.) 
    // http://www.php.net/manual/en/timezones.php has a full list 
    'time_zone' => 'America/Vancouver', 

    // How you want each thing to display.
    // By default, this contains all the bits you can grab. You can put ###DATE### in here too if you want to, and disable the 'group by date' below.
    'event_display' => "<li><a href='###LINK###'><span class='red'>###DATE###</span>&nbsp;###TITLE###</a></li>",

    // The separate date header is here
    'event_dateheader' => "<P><B>###DATE###</b></P>",
    'group_by_date' => false, // change to true if you want to group by dates

    // ...and here's where you tell it to use a cache.
    'use_cache' => true,

  ), $settings);

  date_default_timezone_set($config['time_zone']);

  // What happens if there's nothing to display
  $event_error="<P>There are no events to display.</p>";

  $o = ''; // HTML output variable

  // Form the XML address.
  $calendar_xml_address = str_replace("/basic","/full?singleevents=true&futureevents=true&max-results".$config['items_to_show']."&orderby=starttime&sortorder=a",$config['feed_url']); //This goes and gets future events in your feed.

  $xml = jdgc_get_calendar_xml($config, $calendar_xml_address);

  $items_shown=0;
  $old_date="";
  $xml->asXML();

  foreach ($xml->entry as $entry){
    $ns_gd = $entry->children('http://schemas.google.com/g/2005');

    //Do some niceness to the description
    //Make any URLs used in the description clickable
    $description = preg_replace('"\b(http://\S+)"', '<a href="$1">$1</a>', $entry->content);
    
    // Make email addresses in the description clickable
    $description = preg_replace("`([-_a-z0-9]+(\.[-_a-z0-9]+)*@[-a-z0-9]+(\.[-a-z0-9]+)*\.[a-z]{2,6})`i","<a href=\"mailto:\\1\" title=\"mailto:\\1\">\\1</a>", $description);

    // These are the dates we'll display
    $gCalDate = date_i18n($config['date_format'], strtotime($ns_gd->when->attributes()->startTime));
    $gCalDateStart = date_i18n($config['date_format'], strtotime($ns_gd->when->attributes()->startTime));
    $gCalDateEnd = date_i18n($config['date_format'], strtotime($ns_gd->when->attributes()->endTime));
    $gCalStartTime = date_i18n($config['time_format'], strtotime($ns_gd->when->attributes()->startTime));
    $gCalEndTime = date_i18n($config['time_format'], strtotime($ns_gd->when->attributes()->endTime));
                     
    // Now, let's run it through some str_replaces, and store it with the date for easy sorting later
    $temp_event=$config['event_display'];
    $temp_dateheader=$config['event_dateheader'];
    $temp_event=str_replace("###TITLE###",$entry->title,$temp_event);
    $temp_event=str_replace("###DESCRIPTION###",$description,$temp_event);

    if ($gCalDateStart!=$gCalDateEnd) {
      //This starts and ends on a different date, so show the dates
      $temp_event=str_replace("###DATESTART###",$gCalDateStart,$temp_event);
      $temp_event=str_replace("###DATEEND###",$gCalDateEnd,$temp_event);
    } else {
      $temp_event=str_replace("###DATESTART###",'',$temp_event);
      $temp_event=str_replace("###DATEEND###",'',$temp_event);
    }

    $temp_event=str_replace("###DATE###",$gCalDate,$temp_event);
    $temp_dateheader=str_replace("###DATE###",$gCalDate,$temp_dateheader);
    $temp_event=str_replace("###FROM###",$gCalStartTime,$temp_event);
    $temp_event=str_replace("###UNTIL###",$gCalEndTime,$temp_event);
    $temp_event=str_replace("###WHERE###",$ns_gd->where->attributes()->valueString,$temp_event);
    $temp_event=str_replace("###LINK###",$entry->link->attributes()->href,$temp_event);
    $temp_event=str_replace("###MAPLINK###","http://maps.google.com/?q=".urlencode($ns_gd->where->attributes()->valueString),$temp_event);
    // Accept and translate HTML
    $temp_event=str_replace("&lt;","<",$temp_event);
    $temp_event=str_replace("&gt;",">",$temp_event);
    $temp_event=str_replace("&quot;","\"",$temp_event);
                     
    if (($config['items_to_show']>0 AND $items_shown<$config['items_to_show'])) {
      if ($config['group_by_date']) {
        if ($gCalDate!=$old_date) { $o .= $temp_dateheader; $old_date=$gCalDate;}
      }
      $o .= $temp_event;
      $items_shown++;
    }
  }

  if (!$items_shown) { 
    $o .= $event_error; 
  }

  return $o;
}

// The template tag:
function jdgc_calendar($config) {
  echo jdgc_get_calendar($config);
}

function jdgc_calendar_shortcode($atts) {
  $config = shortcode_atts(array(
    'feed_url' => null,
    'items_to_show' => 10,
  ), $atts);
  return jdgc_get_calendar($config);
}

if (function_exists('add_shortcode')) {
  add_shortcode('jdgc_calendar', 'jdgc_calendar_shortcode');
}

