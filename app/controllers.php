<?php
include "./settings.php";

// optionally include some usefull functions
include "helpers.php";

// API Endpoints


function test() {
	echo "the update worked";
}

function getFeed() {
	$endpoint = "getFeed"; 
	if (_validate(["lat", "lon"])) {
		$checkin = new CheckIn();
		$checkins = $checkin->getAll();

		$return = []; 

		foreach ($checkins as $checkin) {
			$lat1 = floatval($checkin["lat"]); 
			$lon1 = floatval($checkin["lon"]);
			$lat2 = floatval($_GET["lat"]); 
			$lon2 = floatval($_GET["lon"]);
			$distance = distance($lat1, $lon1, $lat2, $lon2, "M");
			
			if ($distance <= 4) {

				$user = new User(); 
				$user->get("facebook_id", $checkin["facebook_id"]);

				$user_info = [];

				$time = $checkin["created_at"]; 
				$now = time(); 
				$deltaTime = $now - $time;

				$user_info["time"] = timeAgo($deltaTime); 
				$user_info["facebook_id"] = $user->facebook_id; 
				$user_info["name"] = $user->first_name . " " . substr($user->last_name, 0, 1) . ".";
				$user_info["distance"] = "" . round($distance, 1) . " miles"; 

				array_push($return, $user_info);
			}

		}

		$return = array_reverse($return); 

		_respond($endpoint, $return); 

	}
}

function inviteOut() {
	$endpoint = "inviteOut"; 
	if (_validate(["phone_number", "facebook_id"])) {
		if (strlen($_GET["phone_number"]) == 10) {

		    require "Twilio/Services/Twilio.php";
		    $AccountSid = "ACbd652dd257ef5f7fdbf246a6e7af8d3a";
		    $AuthToken = "e22f767658650152da61ff7dc93ad57e";
		    $client = new Services_Twilio($AccountSid, $AuthToken);

			$contact = new Contact(); 

			$phone = $_GET["phone_number"];  

			$text = "A friend (they have ur number) is anonymously asking u out tnite. They sent u this txt using the tnite app, available here: http://tnite.io. \n\nWhat u do next is up to u.\n-----------------\nThis isn't spam btw, we freakin hate spam too.\n-the tnite team";

			$invites = -1; 

			if (_contactExists($phone)) {
				$contact->get("phone_number", $phone);
				$invites = intval($contact->invites); 
				$contact->invites = intval($contact->invites) + 1;
				$contact->save();
			} else {
			    $contact->owner_id = $_GET["facebook_id"];
			    $contact->phone_number = $_GET["phone_number"]; 
				$contact->refs = 0; 
				$contact->invites = 0; 
			    $contact->save(); 
			}

		    $sms = $client->account->messages->sendMessage(
		        "516-210-4617", 
		        $_GET["phone_number"],
		        $text
		    );

			$invite = new Invite(); 
			$invite->owner_facebook_id = $_GET["facebook_id"];
			$invite->friend_phone_number = $_GET["phone_number"];
			$invite->save();

		    _respond($endpoint, $invites); 
		} else {
			_respondWithError($endpoint, "Please enter a valid 10 digit phone number."); 
		}
	}
}

function createUser() {
	$endpoint = "createUser"; 
	if (_validate(["facebook_id", "first_name", "last_name", "gender", "email"])) {
		$user = new User(); 
		$found = $user->get("facebook_id", $_GET["facebook_id"]); 
		if ($found === False) {
			$user->facebook_id = $_GET["facebook_id"];
			$user->first_name = ucfirst(strtolower($_GET["first_name"]));
			$user->last_name = ucfirst(strtolower($_GET["last_name"]));
			$user->gender = strtolower($_GET["gender"]);
			$user->email = strtolower($_GET["email"]);
			$user->save();
			_respond($endpoint, $user); 
		} else {
			_respondWithError($endpoint, "That account has already been created.");
		}
		
	}
}

function isGoingOut() { 
	$endpoint = "isGoingOut";
	if (_validate(["facebook_id", "lat", "lon"])) {
		if (_userExists("facebook_id", $_GET["facebook_id"])) {

			$checkin = new CheckIn();	
			$found = $checkin->get("facebook_id", $_GET["facebook_id"]);

			// if ($found) {
			// 	$checkin->delete(); 
			// }

			$checkin = new CheckIn();
			$checkin->facebook_id = $_GET["facebook_id"];  
			$checkin->lat = $_GET["lat"]; 
			$checkin->lon = $_GET["lon"]; 
			$checkin->save(); 

			$checkin = new CheckInHistory();
			$checkin->facebook_id = $_GET["facebook_id"];  
			$checkin->lat = $_GET["lat"]; 
			$checkin->lon = $_GET["lon"]; 
			$checkin->save(); 
			_respond($endpoint, "Checked In"); 

		}
	}
}

// THIS IS NOT DONE YET (YIKES!)
function getGoingOutStatus() { 
	$endpoint = "getGoingOutStatus";
	if (_validate(["facebook_id"])) {
		if (_userExists("facebook_id", $_GET["facebook_id"])) {

			$found = $checkin->get("facebook_id", $_GET["facebook_id"]);

			if ($found) {
				$checkin->delete(); 
			}

			$checkin->facebook_id = $_GET["facebook_id"];  
			$checkin->lat = $_GET["lat"]; 
			$checkin->lon = $_GET["lon"]; 
			$checkin->save(); 
			_respond($endpoint, "Checked In"); 
		}
	}
}


function uploadAddressBook() {
	$endpoint = "uploadAddressBook"; 
	// send json data as array of arrays
	// [0] = ["phone_number", "first_name", "last_name"] 

	$post = file_get_contents('php://input');

	if (_validate(["facebook_id"])) {
		if (isset($post)) {
			if (_userExists("facebook_id", $_GET["facebook_id"])) {
				$address_book = json_decode($post);


				if (is_null($address_book)) {
					_respondWithError($endpoint, "error parsing json"); 
					return; 
				} 

				for ($i=0; $i < sizeof($address_book); $i++) { 
					$creds = $address_book[$i];
					$phone = preg_replace("/[^0-9]/", "", $creds[0]);
					$phone = substr($phone, -10);
					if (strlen($phone) == 10) {
						$contact = new Contact(); 
						if (!_contactExists($phone)) {
							$contact->owner_id = $_GET["facebook_id"];
							$contact->phone_number = "" . $phone;
							$contact->refs = 1; 
							$contact->invites = 0; 
							$contact->first_name = preg_replace("/[^A-Za-z0-9 ]/", "", strtolower($creds[1]));  
							$contact->last_name = preg_replace("/[^A-Za-z0-9 ]/", "", strtolower($creds[2]));
							$contact->save();
						} else {
							$contact->get("phone_number", $phone);
							$contact->refs = intval($contact->refs) + 1; 
							$contact->save(); 
						}
					}
				}

				_respond($endpoint, "Uploaded!"); 
			} else {
				_respondWithError($endpoint, "A user with that ID does not exists."); 
			}
		} else {
			_respondWithError($endpoint, "No post data.");
		}
	} else {
		_respondWithError($endpoint, "No facebook_id."); 
	}
}

// Must include this function. You can change its name in settings.php
function home() {
	// CODE HERE
	include("views/home.php"); 
}

// Must include this function. You can change its name in settings.php
function notfound() {
	// CODE HERE

	include("views/notfound.php"); 
}


// Useful for system wide announcments / debugging
function _everypage() {

}

?>
