<?php
namespace Craft;

class InternalAssets_AssetsController extends BaseController {

    public function actionDownload()
    {
        // find the file by its id
        $fileId = $this->actionParams['variables']['matches']['id'];
        $file = craft()->assets->findFile(array('id' => $fileId));

        if($file) {
            // deliver file to the clienct (if permission granted)
            $this->_sendFile($file);
        }
        else {
            // TODO: redirect to 404 page
            CRAFT::dd('File not found');
        }
    }

    // view items from the control panel
    public function actionView()
    {
        // get the directory and filename submitted
        $directoryName = $this->actionParams['variables']['matches']['directory'];
        $fileName = trim(str_replace('*', '', $this->actionParams['variables']['matches']['name']));

        // create a resolved path from the directory name
        $filePath = craft()->config->parseEnvironmentString($directoryName);

        // Load all transforms to check if the requested file is a transformed version
        $availableTransforms = craft()->assetTransforms->getAllTransforms();
        $requestedTransform = '';

        if (count($availableTransforms) > 0) {
            foreach ($availableTransforms as $transform) {
                // Check if the file path ends with the handle of the specified transform
                if (substr($filePath, -strlen($transform->handle)) === $transform->handle) {
                    $requestedTransform = '_' . $transform->handle;
                }
            }
        }

        // first we find all files with matching file names
        $files = $this->_findFilesByName($fileName);

        // now we have to get the path to the filename
        foreach($files as $file) {
            $match = false;

            // get the full path to the file directory
            $path = $this->_getFullDirectoryPath($file) . $requestedTransform;

            $pathParts = explode('/', $filePath);
            $lastPart = $pathParts[count($pathParts) - 1];

            // Does the last directory start with an underscore, if so, set alternative path?
            if (substr($lastPart, 0, 1) == '_') {
                unset($pathParts[count($pathParts) - 1]);
                $secondPath = implode('/', $pathParts);
            }

            // now will check if our requested filepath matches the files path
            if (strpos($path, $filePath)) {
                $match = true;
            }
            // Check if the original image exists.
            elseif (isset($secondPath) && strpos($path, $secondPath)) {
                $path = str_replace($secondPath, $filePath, $path);

                if (file_exists($path)) {
                    $match = true;
                }
            }

            if ($match) {
                if ($file) {
                    // deliver file to the clienct (if permission granted)
                    $this->_sendFile($file, $path);
                    exit;
                }
            }
        }

        // TODO: redirect to 404 page
         Craft::dd("File not found");

    }

    private function _getFullDirectoryPath($file)
    {
        // get the path to our file and replace environment specific variables
        // NOTE: this will only get the path to the root folder
        $source = $file->getSource();
        $settings = $source->getAttribute('settings');

        $path = $settings['path'];

        // now we have to attach the path to the subfolder
        // NOTE: sub-sub-folders store the path relative to the root (so were good for now)
        $path = $path . $file->folder->getAttribute('path');

        // and parse the path-string as environment variable (resolving placeholders)
        $path = craft()->config->parseEnvironmentString($path);

        return $path;
    }

    // find all files that match the criteria
    private function _findFilesByName($fileName)
    {
		if (!empty($fileName))
		{
			// define our matching criteria
			$match = array('filename' => $fileName);

			// get an element criteria model for our files
			$criteria = craft()->elements->getCriteria(ElementType::Asset, $match);

			// fetch the results from the database
			return $criteria->find();
		}

		return array();
    }

    // send the file to the client
    private function _sendFile($file, $path = NULL)
    {
        // get the full path to the file
        // (if not supplied by calling function)
        if (is_null($path)) {
            $path = $this->_getFullDirectoryPath($file);
        }

        // construct the full filepath
        $filename = $file->getAttribute('filename');
        $filepath = $path . '/' . $filename;

        // the user needs permission to view the asset
        $permission = 'viewassetsource';

        // check if the current user has the required permissions
        $hasAccess = craft()->userSession->checkPermission($permission . ':' . $file->sourceId);

        // Fire an 'onBeforeSendFile' event
        $event = new Event($this, array(
            'hasAccess' => &$hasAccess,
            'asset'   => &$file,
        ));

        craft()->internalAssets->onBeforeSendFile($event);

        if ($hasAccess) {

            // get the files mime type
            $mimeType = $file->getMimeType();

            // use an optimized header for pdfs
            if ($mimeType == "application/pdf") {
                header('Content-type: application/pdf');
                header('Content-Disposition: inline; filename="' . $filename . '"');
                header('Content-Transfer-Encoding: binary');
                header('Content-Length: ' . filesize($filepath));
                header('Accept-Ranges: bytes');
            }
            // use an optimized header for word files
            elseif ($mimeType == "application/msword") {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Transfer-Encoding: binary');
                header('Content-Length: ' . filesize($filepath));
                header('Accept-Ranges: bytes');
            }
            else {
                header('Content-type: '.$file->getMimeType());
            }

            // NOTE: we could also use file_get_contents($filepath)
            // in contrast to readfile this will read the complete file content
            // into a string which can then be sent to the output.
            // This will however use more memory than the readfile-approach.

            // render the file content directly to the output
            readfile($filepath);

        }
        else {
            CRAFT::dd("Permission denied");
        }

    }

}
