<?php

namespace DreamFactory\Core\Utility;

use DreamFactory\Core\Enums\AppTypes;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Models\App;
use DreamFactory\Core\Models\BaseModel;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Services\BaseFileService;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Enums\Verbs;

class Packager
{
    /**
     * Package file extension.
     */
    const FILE_EXTENSION = 'dfpkg';

    /**
     * Default container for app files.
     */
    const DEFAULT_STORAGE_FOLDER = 'applications';

    /**
     * Resource wrapper from config.
     *
     * @type string
     */
    protected $resourceWrapper = null;

    /**
     * Package zip file.
     *
     * @type \ZipArchive
     */
    protected $zip = null;

    /**
     * App ID of the app to export.
     *
     * @type int
     */
    protected $exportAppId = 0;

    /**
     * Services to export.
     *
     * @type array
     */
    protected $exportServices = [];

    /**
     * Schemas to export.
     *
     * @type array
     */
    protected $exportSchemas = [];

    /**
     * @param mixed $fileInfo
     *
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    public function __construct($fileInfo = null)
    {
        if(is_numeric($fileInfo)){
            $this->exportAppId = $fileInfo;
        } else if (is_array($fileInfo)) {
            $this->verifyUploadedFile($fileInfo);
        } else if (!empty($fileInfo) && is_string($fileInfo)) {
            $this->verifyImportFromUrl($fileInfo);
        }
        $this->resourceWrapper = \Config::get('df.resources_wrapper');
    }

    /**
     * Deletes the temp uploaded file and closes the Zip archive
     */
    public function __destruct()
    {
        if($this->zip instanceof \ZipArchive) {
            @unlink($this->zip->filename);
            $this->zip->close();
        }
    }

    /**
     * @param array $services
     * @param array $schemas
     */
    public function setExportItems($services = [], $schemas = [])
    {
        $this->exportServices = $services;
        $this->exportSchemas = $schemas;
    }

    /**
     * @param $file
     *
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    protected function verifyUploadedFile($file)
    {
        if (is_array($file['error'])) {
            throw new BadRequestException("Only a single application package file is allowed for import.");
        }

        if (UPLOAD_ERR_OK !== ($error = $file['error'])) {
            throw new InternalServerErrorException(
                "Failed to receive upload of '" . $file['name'] . "': " . $error
            );
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (static::FILE_EXTENSION != $extension) {
            throw new BadRequestException("Only package files ending with '" .
                static::FILE_EXTENSION .
                "' are allowed for import.");
        }

        $this->setZipFile($file['tmp_name']);
    }

    /**
     * @param $url
     *
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    protected function verifyImportFromUrl($url)
    {
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));

        if (static::FILE_EXTENSION != $extension) {
            throw new BadRequestException("Only package files ending with '" .
                static::FILE_EXTENSION .
                "' are allowed for import.");
        }

        try {
            // need to download and extract zip file and move contents to storage
            $file = FileUtilities::importUrlFileToTemp($url);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to import package $url.\n{$ex->getMessage()}");
        }

        $this->setZipFile($file);
    }

    /**
     * @param string $file
     *
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    private function setZipFile($file)
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($file)) {
            throw new InternalServerErrorException('Error opening zip file.');
        }

        $this->zip = $zip;
    }

    /**
     * @return integer|null
     */
    private function getDefaultStorageServiceId()
    {
        /** @type BaseModel $model */
        $model = Service::whereType('local_file')->first();
        $storageServiceId = ($model) ? $model->{Service::getPrimaryKeyStatic()} : null;

        return $storageServiceId;
    }

    /**
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     */
    private function getAppInfo()
    {
        $this->zip;
        $data = ($this->zip->getFromName('description.json')) ?: $this->zip->getFromName('app.json');
        $this->zip->deleteName('description.json');
        $this->zip->deleteName('app.json');

        if (false === $data) {
            throw new BadRequestException('No application description file in this package file.');
        } else {
            $data = DataFormatter::jsonToArray($data);
            $data['name'] = ArrayUtils::get($data, 'api_name', ArrayUtils::get($data, 'name'));
        }

        return $data;
    }

    /**
     * @param $record
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    private function insertAppRecord($record)
    {
        try {
            $result = ServiceHandler::handleRequest(Verbs::POST, 'system', 'app', ['fields' => '*'], [$record]);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Could not create the application.\n{$ex->getMessage()}");
        }

        return (isset($result[$this->resourceWrapper])) ? $result[$this->resourceWrapper][0] : $result[0];
    }

    /**
     * @return bool
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    private function insertServices()
    {
        $data = $this->zip->getFromName('services.json');
        $this->zip->deleteName('services.json');

        if (false !== $data) {
            $data = DataFormatter::jsonToArray($data);
            try {
                Service::create($data);
            } catch (\Exception $ex) {
                throw new InternalServerErrorException("Could not create the services.\n{$ex->getMessage()}");
            }

            return true;
        }

        return false;
    }

    /**
     * @return bool
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \Exception
     */
    private function insertSchemas()
    {
        $data = $this->zip->getFromName('schema.json');
        $this->zip->deleteName('schema.json');
        if (false !== $data) {
            $data = DataFormatter::jsonToArray($data);
            $services = ArrayUtils::get($data, 'service');
            if (!empty($services)) {
                foreach ($services as $schemas) {
                    $serviceName = ArrayUtils::get($schemas, 'name');
                    $tables = ArrayUtils::get($schemas, 'table');
                    if (!empty($tables)) {
                        try {
                            ServiceHandler::handleRequest(
                                Verbs::POST,
                                $serviceName,
                                '_schema',
                                [],
                                [$this->resourceWrapper => $tables]
                            );
                        } catch (\Exception $e) {
                            if (in_array($e->getCode(), [404, 500])) {
                                throw $e;
                            }
                        }
                    }
                }
            } else {
                throw new BadRequestException("Could not create the database tables for this application.\nDatabase service or schema not found in schema.json.");
            }

            return true;
        }

        return false;
    }

    /**
     * @return bool
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \Exception
     */
    private function insertData()
    {
        $data = $this->zip->getFromName('data.json');
        $this->zip->deleteName('data.json');

        if (false !== $data) {
            $data = DataFormatter::jsonToArray($data);
            $services = ArrayUtils::get($data, 'service');
            if (!empty($services)) {
                foreach ($services as $service) {
                    $serviceName = ArrayUtils::get($service, 'name');
                    $tables = ArrayUtils::get($service, 'table');

                    foreach ($tables as $table) {
                        $tableName = ArrayUtils::get($table, 'name');
                        $records = ArrayUtils::get($table, 'record');

                        try {
                            ServiceHandler::handleRequest(
                                Verbs::POST,
                                $serviceName,
                                '_table/' . $tableName,
                                [],
                                [$this->resourceWrapper => $records]
                            );
                        } catch (\Exception $e) {
                            if (in_array($e->getCode(), [404, 500])) {
                                throw $e;
                            }
                        }
                    }
                }
            } else {
                throw new BadRequestException("Could not create the database tables for this application.\nDatabase service or data not found.");
            }

            return true;
        }

        return false;
    }

    /**
     * @param array $appInfo
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\ForbiddenException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     * @throws \DreamFactory\Core\Exceptions\NotFoundException
     */
    private function storeApplicationFiles($appInfo)
    {
        $appName = ArrayUtils::get($appInfo, 'name');
        $storageServiceId = ArrayUtils::get($appInfo, 'storage_service_id', $this->getDefaultStorageServiceId());
        $storageFolder = ArrayUtils::get($appInfo, 'storage_container', static::DEFAULT_STORAGE_FOLDER);

        /** @var $service BaseFileService */
        $service = ServiceHandler::getServiceById($storageServiceId);
        if (empty($service)) {
            throw new InternalServerErrorException(
                "App record created, but failed to import files due to unknown storage service with id '$storageServiceId'."
            );
        }

        if (empty($storageFolder)) {
            $info = $service->extractZipFile($appName, '', $this->zip, false, $appName . '/');
        } else {
            $info = $service->extractZipFile($storageFolder, '', $this->zip);
        }

        return $info;
    }

    /**
     * @param null $record
     *
     * @return \DreamFactory\Core\Contracts\ServiceResponseInterface|mixed
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     * @throws \Exception
     */
    public function importAppFromPackage($record = null)
    {
        $record = ArrayUtils::clean($record);
        $data = $this->getAppInfo();

        // merge in overriding parameters from request if given
        $record = array_merge($data, $record);

        \DB::beginTransaction();
        $appResults = $this->insertAppRecord($record);

        try {
            $this->insertServices();
            $this->insertSchemas();
            $this->insertData();
            $this->storeApplicationFiles($record);
        } catch (\Exception $ex) {
            //Rollback all db changes;
            \DB::rollBack();

            throw $ex;
        }

        \DB::commit();

        return $appResults;
    }

    public function exportAppAsPackage(
        $includeFiles = true,
        $includeServices = false,
        $includeSchemas = false,
        $includeData = false
    ){
        /** @type App $app */
        $app = App::find($this->exportAppId);

        if (empty($app)) {
            throw new NotFoundException('App not found in database with app id - ' . $this->exportAppId);
        }

        $appName = $app->name;
        $zipFileName = null;

        try {
            $zip = new \ZipArchive();
            $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $zipFileName = $tmpDir . $appName . '.' . static::FILE_EXTENSION;
            $zip->filename = $zipFileName;
            $this->zip = $zip;

            if (true !== $this->zip->open($zipFileName, \ZipArchive::CREATE)) {
                throw new InternalServerErrorException('Can not create package file for this application.');
            }

            $record = [
                'name'                    => $app->name,
                'description'             => $app->description,
                'is_active'               => $app->is_active,
                'type'                    => $app->type,
                'path'                    => $app->path,
                'url'                     => $app->url,
                'requires_fullscreen'     => $app->requires_fullscreen,
                'allow_fullscreen_toggle' => $app->allow_fullscreen_toggle,
                'toggle_location'         => $app->toggle_location
            ];

            if (!$this->zip->addFromString('description.json', json_encode($record, JSON_UNESCAPED_SLASHES))) {
                throw new InternalServerErrorException("Can not include description in package file.");
            }

            if ($app->type === AppTypes::STORAGE_SERVICE && $includeFiles) {
                // add files
                $storageServiceId = $app->storage_service_id;
                $storageFolder = $app->storage_container;

                if (empty($storageServiceId)) {
                    $storageServiceId = $this->getDefaultStorageServiceId();
                }

                if (empty($storageServiceId)) {
                    throw new InternalServerErrorException("Can not find storage service identifier.");
                }

                /** @type BaseFileService $storage */
                $storage = ServiceHandler::getServiceById($storageServiceId);
                if (!$storage) {
                    throw new InternalServerErrorException("Can not find storage service by identifier '$storageServiceId''.");
                }

                if (empty($storageFolder)) {
                    if ($storage->driver()->containerExists($appName)) {
                        $storage->driver()->getFolderAsZip($appName, '', $this->zip, $zipFileName, true);
                    }
                } else {
                    if ($storage->driver()->folderExists($storageFolder, $appName)) {
                        $storage->driver()->getFolderAsZip($storageFolder, $appName, $this->zip, $zipFileName, true);
                    }
                }
            }

            FileUtilities::sendFile($zipFileName, true);
            return null;
        } catch (\Exception $e) {
            //Do necessary things here.

            throw $e;
        }
    }
}