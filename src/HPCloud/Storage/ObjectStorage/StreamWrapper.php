<?php

/**
 * @file
 * Contains the stream wrapper for `swift://` URLs.
 */

namespace HPCloud\Storage\ObjectStorage;

/**
 * Provides stream wrapping for Swift.
 *
 * This provides a full stream wrapper to expose `swift://` URLs to the
 * PHP stream system.
 *
 * URL Structure
 *
 * This takes URLs of the following form:
 *
 * @code
 * swift://CONTAINER/FILE
 * @endcode
 *
 * Example:
 *
 * @code
 * swift://public/example.txt
 * @encode
 *
 * The example above would access the `public` container and attempt to
 * retrieve the file named `example.txt`.
 *
 * Slashes are legal in Swift filenames, so a pathlike URL can be constructed
 * like this:
 *
 * @code
 * swift://public/path/like/file/name.txt
 * @endcode
 *
 * The above would attempt to find a file in object storage named
 * `path/like/file/name.txt`.
 *
 * A note on UTF-8 and URLs: PHP does not yet natively support many UTF-8
 * charcters in URLs. Thus, you ought to urlencode() your container name
 * and object name (path) if there is any possibility that it will contain
 * UTF-8 characters.
 *
 * Usage
 *
 * The principle purpose of this wrapper is to make it easy to access and
 * manipulate objects on a remote object storage instance. Managing
 * containers is a secondary concern (and can often better be managed using
 * the HPCloud API). Consequently, almost all actions done through the
 * stream wrapper are focused on objects, not containers, servers, etc.
 *
 * Retrieving an Existing Object
 *
 * Retrieving an object is done by opening a file handle to that object.
 *
 * Writing an Object
 *
 * Nothing is written to the remote storage until the file is closed. This
 * keeps network traffic at a minimum, and respects the more-or-less stateless
 * nature of ObjectStorage.
 */
class StreamWrapper {

  const DEFAULT_SCHEME = 'swift';

  /**
   * The stream context.
   *
   * This is set automatically when the stream wrapper is created by
   * PHP. Note that it is not set through a constructor.
   */
  public $context;
  protected $contextArray = array();

  protected $schemeName = self::DEFAULT_SCHEME;
  protected $authToken;


  // File flags. These should probably be replaced by O_ const's at some point.
  protected $isBinary = FALSE;
  protected $isText = TRUE;
  protected $isWriting = FALSE;
  protected $isReading = FALSE;
  protected $isTruncating = FALSE;
  protected $isAppending = FALSE;
  protected $noOverwrite = FALSE;
  protected $createIfNotFound = TRUE;

  protected $triggerErrors = FALSE;

  /**
   * Indicate whether the local differs from remote.
   *
   * When the file is modified in such a way that 
   * it needs to be written remotely, the isDirty flag
   * is set to TRUE.
   */
  protected $isDirty = FALSE;

  /**
   * Object storage instance.
   */
  protected $store;

  /**
   * The Container.
   */
  protected $container;

  /**
   * The Object.
   */
  protected $obj;

  /**
   * The IO stream for the Object.
   */
  protected $objStream;


  public function dir_closedir() {
  }

  public function dir_opendir($path, $options) {
    $url = parse_url($path);

    $containerName = $url['host'];

    if (isset($url['path'])) {
      $path = '';
    }
  }

  public function dir_readdir() {
  }

  public function dir_rewinddir() {

  }

  public function mkdir($path, $mode, $options) {

  }

  public function rmdir($path, $options) {

  }

  public function rename($path_from, $path_to) {

  }

  public function stream_cast($cast_as) {

  }

  /**
   * Close a stream, writing if necessary.
   *
   * This will close the present stream. Importantly,
   * this will also write to the remote object storage if
   * any changes have been made locally.
   */
  public function stream_close() {

    // Use save($this->obj, $this->objStream);
    if ($this->isDirty) {
      $this->container->save($this->obj, $this->objStream);
    }
    $this->isDirty = FALSE;

    // Force-clear the memory hogs.
    unset($this->obj);
    unset($this->objStream);
  }

  public function stream_eof() {
  }

  /**
   * Initiate saving data on the remote object storage.
   *
   * If the local copy of this object has been modified,
   * it is written remotely.
   */
  public function stream_flush() {
    // TODO: Should we handle exceptions here?
    if ($this->isDirty) {
      $this->container->save($this->obj, $this->objStream);
    }
    $this->isDirty = FALSE;
  }

  public function stream_lock($operation) {

  }

  public function stream_metadata($path, $option, $var) {

  }

  /**
   * Open a stream resource.
   *
   * This opens a given stream resource and prepares it for reading or writing.
   *
   * If a file is opened in write mode, its contents will be retrieved from the
   * remote storage and cached locally for manipulation. If the file is opened
   * in a write-only mode, the contents will be created locally and then pushed
   * remotely as necessary.
   *
   * During this operation, the remote host may need to be contacted for
   * authentication as well as for file retrieval.
   *
   * @param string $path
   *   The URL to the resource. See the class description for details, but
   *   typically this expects URLs in the form `swift://CONTAINER/OBJECT`.
   * @param string $mode
   *   Any of the documented mode strings. See fopen().
   * @param int $options
   *   An OR'd list of options. Only STREAM_REPORT_ERRORS has any meaning
   *   to this wrapper, as it is not working with local files.
   * @param string $opened_path
   *   This is not used, as this wrapper deals only with remote objects.
   */
  public function stream_open($path, $mode, $options, &$opened_path) {

    //syslog(LOG_WARNING, "I received this URL: " . $path);

    // If STREAM_REPORT_ERRORS is set, we are responsible for
    // all error handling while opening the stream.
    if (STREAM_REPORT_ERRORS & $options) {
      $this->triggerErrors = TRUE;
    }

    // Using the mode string, set the internal mode.
    $this->setMode($mode);

    // Parse the URL.
    $url = $this->parseUrl($path);
    //syslog(LOG_WARNING, print_r($url, TRUE));

    // Container name is required.
    if (empty($url['host'])) {
      //if ($this->triggerErrors) {
        trigger_error('No container name was supplied in ' . $path);
      //}
      return FALSE;
    }

    // A path to an object is required.
    if (empty($url['path'])) {
      //if ($this->triggerErrors) {
        trigger_error('No object name was supplied in ' . $path);
      //}
      return FALSE;
    }

    // We set this because it is possible to bind another scheme name,
    // and we need to know that name if it's changed.
    $this->schemeName = isset($url['scheme']) ? $url['scheme'] : self::DEFAULT_SCHEME;

    // Now we find out the container name. We walk a fine line here, because we don't
    // create a new container, but we don't want to incur heavy network
    // traffic, either. So we have to assume that we have a valid container
    // until we issue our first request.
    $containerName = $url['host'];

    // Object name.
    $objectName = $url['path'];


    // XXX: We reserve the query string for passing additional params.

    $this->initializeObjectStorage();

    //syslog(LOG_WARNING, "Container: " . $containerName);


    // Now we need to get the container. Doing a server round-trip here gives
    // us the peace of mind that we have an actual container.
    $this->container = $this->store->container($containerName);

    // Now we fetch the file. Only under certain circumstances to we generate
    // an error if the file is not found.
    // FIXME: We should probably allow a context param that can be set to 
    // mark the file as lazily fetched.
    try {
      $this->obj = $this->container->object($objectName);
      $stream = $this->obj->stream();
      $streamMeta = strean_get_meta_data($stream);

      // Support 'x' and 'x+' modes.
      if ($this->noOverwrites) {
        //if ($this->triggerErrors) {
          trigger_error('File exists and cannot be overwritten.');
        //}
        return FALSE;
      }

      // If we need to write to it, we need a writable
      // stream. Also, if we need to block reading, this
      // will require creating an alternate stream.
      if ($this->isWriting && ($streamMeta['mode'] == 'r' || !$this->isReading)) {
        $newMode = $this->isReading ? 'rb+' : 'wb';
        $tmpStream = fopen('php://temp', $newMode);
        stream_copy_to_stream($stream, $tmpStream);

        // Skip rewinding if we can.
        if (!$this->isAppending) {
          rewind($tmpStream);
        }

        $this->objStream = $tmpStream;
      }
      else {
        $this->objStream = $this->obj->stream();
      }

      // Append mode requires seeking to the end.
      if ($this->isAppending) {
        fseek($this->objStream, -1, SEEK_END);
      }
    }

    // If a 404 is thrown, we need to determine whether
    // or not a new file should be created.
    catch (\HPCloud\Transport\FileNotFoundException $nf) {

      // For many modes, we just go ahead and create.
      if ($this->createIfNotFound) {
        $this->obj = new Object($objectName);
        $this->objStream = fopen('php://temp', 'rb+');
        $this->isDirty = TRUE;
      }
      else {
        //if ($this->triggerErrors) {
          trigger_error($nf->getMessage());
        //}
        return FALSE;
      }

    }
    // All other exceptions are fatal.
    catch (\HPCloud\Exception $e) {
      //if ($this->triggerErrors) {
        trigger_error($e->getMessage());
      //}
      return FALSE;
    }

    // At this point, we have a file that may be read-only. It also may be
    // reading off of a socket. It will be positioned at the beginning of
    // the stream.

    return TRUE;
  }

  public function stream_read($count) {

  }

  public function stream_seek($offset, $whence) {

  }

  public function stream_set_option($option, $arg1, $arg2) {

  }

  public function stream_stat() {

  }

  public function stream_tell() {
  }

  public function stream_write($data) {
    $this->isDirty = TRUE;

  }

  public static function unlink($path) {

  }

  public static function url_stat($path, $flags) {

  }

  ///////////////////////////////////////////////////////////////////
  // INTERNAL METHODS
  // All methods beneath this line are not part of the Stream API.
  ///////////////////////////////////////////////////////////////////

  protected function setMode($mode) {
    $mode = strtolower($mode);

    // These are largely ignored, as the remote
    // object storage does not distinguish between
    // text and binary files. Per the PHP recommendation
    // files are treated as binary.
    $this->isBinary = strpos($mode, 'b') !== FALSE;
    $this->isText = strpos($mode, 't') !== FALSE;

    // Rewrite mode to remove b or t:
    preg_replace('/[bt]?/', '', $mode);

    switch ($mode) {
      case 'r+':
        $this->isWriting = TRUE;
      case 'r':
        $this->isReading = TRUE;
        $this->createIfNotFound = FALSE;
        break;


      case 'w+':
        $this->isReading = TRUE;
      case 'w':
        $this->isTruncating = TRUE;
        $this->isWriting = TRUE;
        break;


      case 'a+':
        $this->isReading = TRUE;
      case 'a':
        $this->isAppending = TRUE;
        $this->isWriting = TRUE;
        break;


      case 'x+':
        $this->isReading = TRUE;
      case 'x':
        $this->isWriting = TRUE;
        $this->noOverwrite = TRUE;
        break;

      case 'c+':
        $this->isReading = TRUE;
      case 'c':
        $this->isWriting = TRUE;
        break;

      // Default case is read/write
      // like c+.
      default:
        $this->isReading = TRUE;
        $this->isWriting = TRUE;

    }

  }

  /**
   * Get an item out of the context.
   */
  protected function cxt($name, $default = NULL) {

    // Lazilly populate the context array.
    if (empty($this->contextArray)) {
      $cxt = stream_context_get_options($this->context);

      // If a custom scheme name has been set, use that.
      if (!empty($cxt[$this->schemeName])) {
        $this->contextArray = $cxt[$this->schemeName];
      }
      // We fall back to this just in case.
      elseif (!empty($cxt[self::DEFAULT_SCHEME])) {
        $this->contextArray = $cxt[self::DEFAULT_SCHEME];
      }
    }

    // Should this be array_key_exists()?
    if (isset($this->contextArray[$name])) {
      return $this->contextArray[$name];
    }

    return $default;
  }

  protected function parseUrl($url) {
    $res = parse_url($url);

    // Hostname is not UTF-8, yet container names
    // can be UTF-8, so we adjust here.
    /*
    $matches = array();
    preg_match('|://([^/]+)|', $url, $matches);
    if (isset($matches[1])) {
      $res['host2'] = $matches[1];
    }
    $res += $matches;
     */
    // These have to be decode because they will later
    // be encoded.
    foreach ($res as $key => $val) {
      if ($key == 'host' || $key == 'path') {
        $res[$key] = urldecode($val);
      }
    }

    return $res;
  }

  /**
   * Based on the context, initialize the ObjectStorage.
   */
  protected function initializeObjectStorage() {

    $token = $this->cxt('token');
    $endpoint = $this->cxt('swift_endpoint');


    // If context has the info we need, start from there.
    if (!empty($token) && !empty($endpoint)) {
      $this->store = new \HPCloud\Storage\ObjectStorage($token, $endpoint);
    }
    // Try to authenticate and get a new token.
    else {
      // Now we need to get the following things from context:
      // - Account name
      // - Account key
      $account = $this->cxt('account');
      $key = $this->cxt('key');
      $baseURL = $this->cxt('endpoint');

      if (empty($baseUrl)) {
        trigger_error('account, endpoint, key are required stream parameters.');
      }
      $this->store = \HPCloud\Storage\ObjectStorage::newFromSwiftAuth($account, $key, $baseURL);
    }

    return !empty($this->ostore);

  }

}