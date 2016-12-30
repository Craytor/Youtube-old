<?php namespace Craytor\Youtube;

use DB;
use Carbon\Carbon;

class Youtube
{
	protected $client;
	protected $youtube;
    	private $videoId;
    	private $thumbnailUrl;
	/**
	 * Constructor accepts the Google Client object, whilst setting the configuration options.
	 * @param \Google_Client $client
	 */
	public function __construct($development = false)
	{
		$this->client = new \Google_Client;
		$this->client->setApplicationName(config('youtube.application_name'));
		$this->client->setClientId(config('youtube.client_id'));
		$this->client->setClientSecret(config('youtube.client_secret'));
		$this->client->setScopes(config('youtube.scopes'));
		$this->client->setAccessType(config('youtube.access_type'));
		$this->client->setApprovalPrompt(config('youtube.approval_prompt'));
		$this->client->setClassConfig('Google_Http_Request', 'disable_gzip', true);

		$this->client->setRedirectUri(url(
			config('youtube.routes.prefix') . '/' . config('youtube.routes.redirect_uri')
		));

		$this->youtube = new \Google_Service_YouTube($this->client);

		$accessToken = $this->getLatestAccessTokenFromDB();

		if ($accessToken) {
			$this->client->setAccessToken($accessToken);
		}
	}

	/**
	 * Saves the access token to the database.
	 * @param $accessToken
	 */
	public function saveAccessTokenToDB($accessToken)
	{
		$data = [
			'access_token' => $accessToken,
			'created_at' => Carbon::now(),
		];

		DB::table('youtube_access_tokens')->insert($data);
	}
	
	/**
	 * Returns the last saved access token, if there is one, or null
	 * @return mixed
	 */
	public function getLatestAccessTokenFromDB()
	{
		$latest = DB::table('youtube_access_tokens')
				->orderBy('created_at', 'desc')
				->first();

		if ($latest) { return $latest->access_token; }

		return null;
	}

	/**
	 * Upload the video to YouTube
	 * @param  string 	$path    	The path to the file you wish to upload.
	 * @param  array 	$snippet 	An array of data.
	 * @param  string 	$status  	The status of the uploaded video, set to 'public' by default.
	 * @return mixed
	 */
	public function upload($path, array $data, $privacyStatus = 'public')
	{
		$this->handleAccessToken();

		/* ------------------------------------
		#. Setup the Snippet
		------------------------------------ */
		$snippet = new \Google_Service_YouTube_VideoSnippet();

		if (array_key_exists('title', $data)) 			$snippet->setTitle($data['title']);
		if (array_key_exists('description', $data)) 	$snippet->setDescription($data['description']);
		if (array_key_exists('tags', $data)) 			$snippet->setTags($data['tags']);
		if (array_key_exists('category_id', $data)) 	$snippet->setCategoryId($data['category_id']);

		/* ------------------------------------
		#. Set the Privacy Status
		------------------------------------ */
		$status = new \Google_Service_YouTube_VideoStatus();
		$status->privacyStatus = $privacyStatus;

		/* ------------------------------------
		#. Set the Snippet & Status
		------------------------------------ */
		$video = new \Google_Service_YouTube_Video();
		$video->setSnippet($snippet);
		$video->setStatus($status);

		/* ------------------------------------
		#. Set the Chunk Size
		------------------------------------ */
		$chunkSize = 1 * 1024 * 1024;

		/* ------------------------------------
		#. Set the defer to true
		------------------------------------ */
		$this->client->setDefer(true);

		/* ------------------------------------
		#. Build the request
		------------------------------------ */
		$insert = $this->youtube->videos->insert('status,snippet', $video);

		/* ------------------------------------
		#. Upload
		------------------------------------ */
		$media = new \Google_Http_MediaFileUpload(
			$this->client,
			$insert,
			'video/*',
			null,
			true,
			$chunkSize
		);

		/* ------------------------------------
		#. Set the Filesize
		------------------------------------ */
		$media->setFileSize(filesize($path));

		/* ------------------------------------
		#. Read the file and upload in chunks
		------------------------------------ */
		$status = false;
		$handle = fopen($path, "rb");

		while (!$status && !feof($handle)) {
			$chunk = fread($handle, $chunkSize);
			$status = $media->nextChunk($chunk);
		}

		fclose($handle);

		$this->client->setDefer(false);

		
        /* ------------------------------------
        #. Set the Uploaded Video ID
        ------------------------------------ */
        $this->videoId = $status['id'];

        return $this;
	}

	/**
	 * Set a Custom Thumbnail for the Upload
	 * @param  string $imagePath
	 * @return [type]            [description]
	 */
    function withThumbnail($imagePath)
    {
        try {
            $videoId = $this->getVideoId();
            // Specify the size of each chunk of data, in bytes. Set a higher value for
            // reliable connection as fewer chunks lead to faster uploads. Set a lower
            // value for better recovery on less reliable connections.
            $chunkSizeBytes = 1 * 1024 * 1024;
            // Setting the defer flag to true tells the client to return a request which can be called
            // with ->execute(); instead of making the API call immediately.
            $this->client->setDefer(true);
            // Create a request for the API's thumbnails.set method to upload the image and associate
            // it with the appropriate video.
            $setRequest = $this->youtube->thumbnails->set($videoId);
            // Create a MediaFileUpload object for resumable uploads.
            $media = new \Google_Http_MediaFileUpload(
                $this->client,
                $setRequest,
                'image/png',
                null,
                true,
                $chunkSizeBytes
            );
            $media->setFileSize(filesize($imagePath));
            // Read the media file and upload it chunk by chunk.
            $status = false;
            $handle = fopen($imagePath, "rb");
            while (!$status && !feof($handle)) {
                $chunk  = fread($handle, $chunkSizeBytes);
                $status = $media->nextChunk($chunk);
            }
            fclose($handle);
            // If you want to make other calls after the file upload, set setDefer back to false
            $this->client->setDefer(false);
            $this->thumbnailUrl = $status['items'][0]['default']['url'];

            return $this;

        } catch (Google_Service_Exception $e) {
            die($e->getMessage());
        } catch (Google_Exception $e) {
            die($e->getMessage());
        }
    }

    /**
     * Return the Video ID
     * 
     * @return string
     */
    function getVideoId()
    {
        return $this->videoId;
    }

    /**
     * Return the URL for the Custom Thumbnail
     * 
     * @return string
     */
    function getThumbnailUrl()
    {
        return $this->thumbnailUrl;
    }

	/**
	 * Delete a YouTube video by it's ID.
	 * 
	 * @param  integer $youtube_id
	 * @return Boolean
	 */
	public function delete($id)
	{
		$this->handleAccessToken();

		if($this->exists($id))
		{
			$this->youtube->videos->delete($id);

			return true;
		}

		return false;
	}

	/**
	 * Check if a YouTube video exists by it's ID.
	 * @param  integer $id
	 * @return boolean
	 */
	public function exists($id)
	{
		$this->handleAccessToken();

		$response = $this->youtube->videos->listVideos('status', ['id' => $id]);

		if(empty($response->items)) return false;

		return true;
	}

	/**
	 * Handle the Access token
	 * 
	 * @return mixed
	 */
	private function handleAccessToken()
	{
		$accessToken = $this->client->getAccessToken();

		if(is_null($accessToken))
		{
			throw new \Exception('An access token is required to delete a video.');
		}

		if($this->client->isAccessTokenExpired())
		{
			$accessToken = json_decode($accessToken);
			$refreshToken = $accessToken->refresh_token;
			$this->client->refreshToken($refreshToken);
			$newAccessToken = $this->client->getAccessToken();
			$this->saveAccessTokenToDB($newAccessToken);
		}
	}
	
	/**
	 * Pass method calls to the Google Client.
	 * @param  $method
	 * @param  $args
	 * @return mixed
	 */
	public function __call($method, $args)
	{
		return call_user_func_array([$this->client, $method], $args);
	}
	
		/**
	 * Updates a video that was uploaded to YouTube
	 * @param  string 	$id    		The YouTube ID of the video in question.
	 * @param  array 	$snippet 	An array of data.
	 * @param  string 	$status  	The status of the uploaded video, set to 'public' by default.
	 * @return mixed
	 */
	public function update($id, array $data, $privacyStatus = 'public') {

		$this->handleAccessToken();

		$listResponse = $this->youtube->videos->listVideos("snippet,status", array('id' => $id));

		$video = $listResponse[0];
		$videoSnippet = $video['snippet'];

		if (array_key_exists('title', $data)) 			$videoSnippet->setTitle($data['title']);
		if (array_key_exists('description', $data)) 	$videoSnippet->setDescription($data['description']);
		if (array_key_exists('tags', $data)) 			$videoSnippet->setTags($data['tags']);
		if (array_key_exists('category_id', $data)) 	$videoSnippet->setCategoryId($data['category_id']);


		// set the video status
		$videoStatus = $video['status'];
		$videoStatus->privacyStatus = $privacyStatus; #privacyStatus options are public, private, and unlisted
		$video->setStatus($videoStatus);

		$updateResponse = $this->youtube->videos->update("status,snippet", $video);

		return;

	}

}
