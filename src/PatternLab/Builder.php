<?php

/*!
 * Builder Class
 *
 * Copyright (c) 2013-2014 Dave Olsen, http://dmolsen.com
 * Licensed under the MIT license
 *
 * Holds most of the "generate" functions used in the the Generator and Watcher class
 *
 */

namespace PatternLab;

use \PatternLab\Config;
use \PatternLab\Data;
use \PatternLab\Parsers\Documentation;
use \PatternLab\PatternData\Exporters\NavItemsExporter;
use \PatternLab\PatternData\Exporters\PatternPartialsExporter;
use \PatternLab\PatternData\Exporters\PatternPathDestsExporter;
use \PatternLab\PatternData\Exporters\ViewAllPathsExporter;
use \PatternLab\PatternEngine;
use \PatternLab\Render;
use \PatternLab\Template;

class Builder {
	
	/**
	* When initializing the Builder class make sure the template helper is set-up
	*/
	public function __construct() {
		
		//$this->patternCSS   = array();
		
		// set-up the pattern engine
		PatternEngine::init();
		
		// set-up the various attributes for rendering templates
		Template::init();
		
	}
	
	/**
	* Finds Media Queries in CSS files in the source/css/ dir
	*
	* @return {Array}        an array of the appropriate MQs
	*/
	protected function gatherMQs() {
		
		$mqs = array();
		
		// iterate over all of the other files in the source directory
		$directoryIterator = new \RecursiveDirectoryIterator(Config::$options["sourceDir"]);
		$objects           = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::SELF_FIRST);
		
		// make sure dots are skipped
		$objects->setFlags(\FilesystemIterator::SKIP_DOTS);
		
		foreach ($objects as $name => $object) {
			
			if ($object->isFile() && ($object->getExtension() == "css")) {
				
				$data = file_get_contents($object->getPathname());
				preg_match_all("/(min|max)-width:([ ]+)?(([0-9]{1,5})(\.[0-9]{1,20}|)(px|em))/",$data,$matches);
				foreach ($matches[3] as $match) {
					if (!in_array($match,$mqs)) {
						$mqs[] = $match;
					}
				}
				
			}
			
		}
		
		usort($mqs, "strnatcmp");
		
		return $mqs;
		
	}
	
	protected function generateAnnotations() {
		
		$annotations             = array();
		$annotations["comments"] = array();
		
		// iterate over all of the files in the annotations dir
		$directoryIterator = new \RecursiveDirectoryIterator(Config::$options["sourceDir"]."/_annotations");
		$objects           = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::SELF_FIRST);
		
		// make sure dots are skipped
		$objects->setFlags(\FilesystemIterator::SKIP_DOTS);
		
		foreach ($objects as $name => $object) {
			
			// if it's an .md file parse and generate the proper info
			if ($object->isFile() && ($object->getExtension() == "md")) {
				
				$data    = array();
				$data[0] = array();
				
				$text = file_get_contents($object->getPathname());
				list($yaml,$markdown) = Documentation::parse($text);
				
				if (isset($yaml["el"]) || isset($yaml["selector"])) {
					$data[0]["el"]  = (isset($yaml["el"])) ? $yaml["el"] : $yaml["selector"];
				} else {
					$data[0]["el"]  = "#someimpossibleselector";
				}
				$data[0]["title"]   = isset($yaml["title"]) ? $yaml["title"] : "";
				$data[0]["comment"] = $markdown;
				
				$annotations["comments"] = array_merge($annotations["comments"],$data);
				
			}
			
		}
		
		// read in the old style annotations.js, modify the data and generate JSON array to merge
		if (file_exists(Config::$options["sourceDir"]."/_annotations/annotations.js")) {
			$text = file_get_contents(Config::$options["sourceDir"]."/_annotations/annotations.js");
			$text = str_replace("var comments = ","",$text);
			$text = rtrim($text,";");
			$data = json_decode($text,true);
			if ($jsonErrorMessage = JSON::hasError()) {
				JSON::lastErrorMsg("_annotations/annotations.js",$jsonErrorMessage,$data);
			}
		}
		
		// merge in any data from the old file
		$annotations["comments"] = array_merge($annotations["comments"],$data["comments"]);
		
		// encode the content so it can be written out
		$json = json_encode($annotations);
		
		// make sure annotations/ exists
		if (!is_dir(Config::$options["publicDir"]."/annotations")) {
			mkdir(Config::$options["publicDir"]."/annotations");
		}
		
		// write out the new annotations.js file
		file_put_contents(Config::$options["publicDir"]."/annotations/annotations.js","var comments = ".$json.";");
		
	}
	
	/**
	* Generates the data that powers the index page
	*/
	protected function generateIndex() {
		
		$dataDir = Config::$options["publicDir"]."/styleguide/data";
		
		// double-check that the data directory exists
		if (!is_dir($dataDir)) {
			mkdir($dataDir);
		}
		
		// load and write out the config options
		$config                      = array();
		$config["autoreloadnav"]     = Config::$options["autoReloadNav"];
		$config["autoreloadport"]    = Config::$options["autoReloadPort"];
		$config["cacheBuster"]       = Config::$options["cacheBuster"];
		$config["ipaddress"]         = getHostByName(getHostName());
		$config["pagefollownav"]     = Config::$options["pageFollowNav"];
		$config["pagefollowport"]    = Config::$options["pageFollowPort"];
		$config["xiphostname"]       = Config::$options["xipHostname"];
		$config["ishminimum"]        = Config::$options["ishMinimum"];
		$config["ishmaximum"]        = Config::$options["ishMaximum"];
		$config["qrcodegeneratoron"] = Config::$options["qrCodeGeneratorOn"];
		file_put_contents($dataDir."/config.js","var config = ".json_encode($config).";");
		
		// load the ish Controls
		$ishControls                    = array();
		$ishControls["ishControlsHide"] = Config::$options["ishControlsHide"];
		$ishControls["mqs"]             = $this->gatherMQs();
		file_put_contents($dataDir."/ish-controls.js","var ishControls = ".json_encode($ishControls).";");
		
		// load and write out the items for the navigation
		$niExporter   = new NavItemsExporter();
		$navItems     = $niExporter->run();
		file_put_contents($dataDir."/nav-items.js","var navItems = ".json_encode($navItems).";");
		
		// load and write out the items for the pattern paths
		$patternPaths = array();
		$ppdExporter  = new PatternPathDestsExporter();
		$patternPaths = $ppdExporter->run();
		file_put_contents($dataDir."/pattern-paths.js","var patternPaths = ".json_encode($patternPaths).";");
		
		// load and write out the items for the view all paths
		$viewAllPaths = array();
		$vapExporter  = new ViewAllPathsExporter();
		$viewAllPaths = $vapExporter->run($navItems);
		file_put_contents($dataDir."/viewall-paths.js","var viewAllPaths = ".json_encode($viewAllPaths).";");
		
	}
	
	/**
	* Generates all of the patterns and puts them in the public directory
	*/
	protected function generatePatterns() {
		
		// set-up common vars
		$patternPublicDir = Config::$options["patternPublicDir"];
		$patternSourceDir = Config::$options["patternSourceDir"];
		$patternExtension = Config::$options["patternExtension"];
		
		// make sure patterns exists
		if (!is_dir($patternPublicDir)) {
			mkdir($patternPublicDir);
		}
		
		// loop over the pattern data store to render the individual patterns
		foreach (PatternData::$store as $patternStoreKey => $patternStoreData) {
			
			if (($patternStoreData["category"] == "pattern") && (!$patternStoreData["hidden"])) {
				
				$path          = $patternStoreData["pathDash"];
				$pathName      = (isset($patternStoreData["pseudo"])) ? $patternStoreData["pathOrig"] : $patternStoreData["pathName"];
				
				// modify the pattern mark-up
				$markup        = $patternStoreData["code"];
				$markupEncoded = htmlentities($markup);
				$markupFull    = $patternStoreData["header"].$markup.$patternStoreData["footer"];
				$markupEngine  = htmlentities(file_get_contents($patternSourceDir."/".$pathName.".".$patternExtension));
				
				// if the pattern directory doesn't exist create it
				if (!is_dir($patternPublicDir."/".$path)) {
					mkdir($patternPublicDir."/".$path);
				}
				
				// write out the various pattern files
				file_put_contents($patternPublicDir."/".$path."/".$path.".html",$markupFull);
				file_put_contents($patternPublicDir."/".$path."/".$path.".escaped.html",$markupEncoded);
				file_put_contents($patternPublicDir."/".$path."/".$path.".".$patternExtension,$markupEngine);
				/*
				Not being used and should be moved to a plug-in
				if (Config::$options["enableCSS"] && isset($this->patternCSS[$p])) {
					file_put_contents($patternPublicDir.$path."/".$path.".css",htmlentities($this->patternCSS[$p]));
				}
				*/
				
			}
			
		}
		
	}
	
	/**
	* Generates the style guide view
	*/
	protected function generateStyleguide() {
		
		if (!is_dir(Config::$options["publicDir"]."/styleguide/html/")) {
			
			print "ERROR: the main style guide wasn't written out. make sure public/styleguide exists. can copy core/styleguide\n";
			
		} else {
			
			// grab the partials into a data object for the style guide
			$ppExporter                 = new PatternPartialsExporter();
			$partials                   = $ppExporter->run();
			
			// add the pattern data so it can be exported
			$patternData = array();
			
			// add the pattern lab specific mark-up
			$partials["patternLabHead"] = Render::Header(Helper::$htmlHead,array("cacheBuster" => $partials["cacheBuster"]));
			$partials["patternLabFoot"] = Render::Footer(Helper::$htmlFoot,array("cacheBuster" => $partials["cacheBuster"], "patternData" => json_encode($patternData)));
			
			$header                     = Render::Header(Helper::$patternHead,$partials);
			$code                       = Helper::$filesystemLoader->render("viewall",$partials);
			$footer                     = Render::Footer(Helper::$patternFoot,$partials);
			$styleGuidePage             = $header.$code.$footer;
			
			file_put_contents(Config::$options["publicDir"]."/styleguide/html/styleguide.html",$styleGuidePage);
			
		}
		
	}
	
	/**
	* Generates the view all pages
	*/
	protected function generateViewAllPages() {
		
		$patternPublicDir = Config::$options["patternPublicDir"];
		
		// add view all to each list
		foreach (PatternData::$store as $patternStoreKey => $patternStoreData) {
			
			if ($patternStoreData["category"] == "patternSubtype") {
				
				// grab the partials into a data object for the style guide
				$ppExporter  = new PatternPartialsExporter();
				$partials    = $ppExporter->run($patternStoreData["type"],$patternStoreData["name"]);
				
				if (!empty($partials["partials"])) {
					
					// add the pattern data so it can be exported
					$patternData = array();
					$patternData["patternPartial"] = "viewall-".$patternStoreData["typeDash"]."-".$patternStoreData["nameDash"];
					
					// add the pattern lab specific mark-up
					$partials["patternLabHead"] = Render::Header(Helper::$htmlHead,array("cacheBuster" => $partials["cacheBuster"]));
					$partials["patternLabFoot"] = Render::Footer(Helper::$htmlFoot,array("cacheBuster" => $partials["cacheBuster"], "patternData" => json_encode($patternData)));
					
					// render the parts and join them
					$header      = Render::Header(Helper::$patternHead,$partials);
					$code        = Helper::$filesystemLoader->render("viewall",$partials);
					$footer      = Render::Footer(Helper::$patternFoot,$partials);
					$viewAllPage = $header.$code.$footer;
					
					// if the pattern directory doesn't exist create it
					$patternPath = $patternStoreData["pathDash"];
					if (!is_dir($patternPublicDir."/".$patternPath)) {
						mkdir($patternPublicDir."/".$patternPath);
						file_put_contents($patternPublicDir."/".$patternPath."/index.html",$viewAllPage);
					} else {
						file_put_contents($patternPublicDir."/".$patternPath."/index.html",$viewAllPage);
					}
					
				}
				
			} else if (($patternStoreData["category"] == "patternType") && PatternData::hasPatternSubtype($patternStoreData["nameDash"])) {
				
				// grab the partials into a data object for the style guide
				$ppExporter  = new PatternPartialsExporter();
				$partials    = $ppExporter->run($patternStoreData["name"]);
				
				if (!empty($partials["partials"])) {
					
					// add the pattern data so it can be exported
					$patternData = array();
					$patternData["patternPartial"] = "viewall-".$patternStoreData["nameDash"]."-all";
					
					// add the pattern lab specific mark-up
					$partials["patternLabHead"] = Render::Header(Helper::$htmlHead,array("cacheBuster" => $partials["cacheBuster"]));
					$partials["patternLabFoot"] = Render::Footer(Helper::$htmlFoot,array("cacheBuster" => $partials["cacheBuster"], "patternData" => json_encode($patternData)));
					
					// render the parts and join them
					$header      = Render::Header(Helper::$patternHead,$partials);
					$code        = Helper::$filesystemLoader->render("viewall",$partials);
					$footer      = Render::Footer(Helper::$patternFoot,$partials);
					$viewAllPage = $header.$code.$footer;
					
					// if the pattern directory doesn't exist create it
					$patternPath = $patternStoreData["pathDash"];
					if (!is_dir($patternPublicDir."/".$patternPath)) {
						mkdir($patternPublicDir."/".$patternPath);
						file_put_contents($patternPublicDir."/".$patternPath."/index.html",$viewAllPage);
					} else {
						file_put_contents($patternPublicDir."/".$patternPath."/index.html",$viewAllPage);
					}
					
				}
				
			}
			
		}
		
	}
	
	/**
	* Loads the CSS from source/css/ into CSS Rule Saver to be used for code view
	* Will eventually get pushed elsewhere
	*/
	protected function initializeCSSRuleSaver() {
		
		$loader = new \SplClassLoader('CSSRuleSaver', __DIR__.'/../../lib');
		$loader->register();
		
		$this->cssRuleSaver = new \CSSRuleSaver\CSSRuleSaver;
		
		foreach(glob(Config::$options["sourceDir"]."/css/*.css") as $filename) {
			$this->cssRuleSaver->loadCSS($filename);
		}
		
	}
	
}
