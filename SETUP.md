# Detailed Setup Guide

This guide will walk you through setting up the IoT Camera Dashboard from scratch.

## Table of Contents
1. [Azure Services Setup](#azure-services-setup)
2. [Raspberry Pi Configuration](#raspberry-pi-configuration)
3. [Web Server Setup](#web-server-setup)
4. [Configuration](#configuration)
5. [Testing](#testing)

## Azure Services Setup

### 1. Azure IoT Hub

#### Create IoT Hub
```bash
# Using Azure CLI
az iot hub create --name your-iot-hub --resource-group your-rg --sku S1 --location eastus
```

#### Register Device
```bash
# Register Raspberry Pi device
az iot hub device-identity create --hub-name your-iot-hub --device-id raspberrypi
```

#### Get Connection String
```bash
# Get IoT Hub connection string
az iot hub connection-string show --hub-name your-iot-hub --policy-name service
```

### 2. Azure Blob Storage

#### Create Storage Account
```bash
# Create storage account
az storage account create --name yourstorageaccount --resource-group your-rg --location eastus --sku Standard_LRS
```

#### Create Container
```bash
# Create container for images
az storage container create --name camera-images --account-name yourstorageaccount --public-access blob
```

#### Get Storage Keys
```bash
# Get storage account keys
az storage account keys list --account-name yourstorageaccount
```

### 3. Azure Computer Vision

#### Create Computer Vision Resource
```bash
# Create Computer Vision resource
az cognitiveservices account create --name your-computer-vision --resource-group your-rg --kind ComputerVision --sku S1 --location eastus
```

#### Get API Key and Endpoint
```bash
# Get Computer Vision keys
az cognitiveservices account keys list --name your-computer-vision --resource-group your-rg

# Get endpoint
az cognitiveservices account show --name your-computer-vision --resource-group your-rg --query properties.endpoint
```

## Raspberry Pi Configuration

### 1. Install Dependencies

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install Python and pip
sudo apt install python3 python3-pip -y

# Install Azure IoT SDK
pip3 install azure-iot-device azure-storage-blob
```

### 2. Camera Setup

```bash
# Enable camera
sudo raspi-config
# Navigate to Interfacing Options > Camera > Enable

# Test camera
raspistill -o test.jpg
```

### 3. Sample Pi Code

Create `camera_client.py`:

```python
import asyncio
import json
import os
from datetime import datetime
from azure.iot.device.aio import IoTHubDeviceClient
from azure.iot.device import MethodResponse
from azure.storage.blob import BlobServiceClient
import subprocess
import uuid

# Configuration
DEVICE_CONNECTION_STRING = "your-device-connection-string"
STORAGE_CONNECTION_STRING = "your-storage-connection-string"
CONTAINER_NAME = "camera-images"

class CameraDevice:
    def __init__(self):
        self.client = IoTHubDeviceClient.create_from_connection_string(DEVICE_CONNECTION_STRING)
        self.blob_service_client = BlobServiceClient.from_connection_string(STORAGE_CONNECTION_STRING)

    async def capture_image(self, payload):
        try:
            # Generate unique filename
            timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
            unique_id = str(uuid.uuid4())[:8]
            filename = f"analyzed_capture_{timestamp}_{unique_id}.jpg"
            
            # Capture image
            subprocess.run(["raspistill", "-o", f"/tmp/{filename}", "-w", "1024", "-h", "768"])
            
            # Upload to Azure Storage
            blob_client = self.blob_service_client.get_blob_client(
                container=CONTAINER_NAME, 
                blob=filename
            )
            
            with open(f"/tmp/{filename}", "rb") as data:
                blob_client.upload_blob(data, overwrite=True)
            
            # Clean up local file
            os.remove(f"/tmp/{filename}")
            
            return {
                "status": "success",
                "filename": filename,
                "timestamp": datetime.now().isoformat()
            }
            
        except Exception as e:
            return {
                "status": "error",
                "message": str(e)
            }

    async def method_handler(self, method_request):
        if method_request.name == "capture_image":
            result = await self.capture_image(method_request.payload)
            response = MethodResponse.create_from_method_request(
                method_request, 200, result
            )
        else:
            response = MethodResponse.create_from_method_request(
                method_request, 404, {"message": "Method not found"}
            )
        
        await self.client.send_method_response(response)

    async def run(self):
        await self.client.connect()
        self.client.on_method_request_received = self.method_handler
        print("Camera device ready. Waiting for commands...")
        
        while True:
            await asyncio.sleep(1)

if __name__ == "__main__":
    device = CameraDevice()
    asyncio.run(device.run())
```

### 4. Run as Service

Create `/etc/systemd/system/camera-service.service`:

```ini
[Unit]
Description=IoT Camera Service
After=network.target

[Service]
Type=simple
User=pi
WorkingDirectory=/home/pi/camera
ExecStart=/usr/bin/python3 /home/pi/camera/camera_client.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable and start:
```bash
sudo systemctl enable camera-service
sudo systemctl start camera-service
```

## Web Server Setup

### 1. Apache Setup

```bash
# Install Apache and PHP
sudo apt install apache2 php libapache2-mod-php -y

# Enable mod_rewrite
sudo a2enmod rewrite

# Restart Apache
sudo systemctl restart apache2
```

### 2. Nginx Setup (Alternative)

```bash
# Install Nginx and PHP-FPM
sudo apt install nginx php-fpm -y

# Configure Nginx
sudo nano /etc/nginx/sites-available/camera-dashboard
```

Nginx configuration:
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/camera-dashboard;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 3. Deploy Code

```bash
# Clone repository
git clone https://github.com/yourusername/iot-camera-dashboard.git
sudo mv iot-camera-dashboard /var/www/
sudo chown -R www-data:www-data /var/www/iot-camera-dashboard
sudo chmod -R 755 /var/www/iot-camera-dashboard
```

## Configuration

### 1. Environment Variables (Recommended)

Create `.env` file:
```bash
IOT_HUB_CONNECTION_STRING="HostName=your-hub.azure-devices.net;SharedAccessKeyName=service;SharedAccessKey=your-key"
COMPUTER_VISION_ENDPOINT="https://your-region.cognitiveservices.azure.com/"
COMPUTER_VISION_KEY="your-computer-vision-key"
```

### 2. Direct Configuration

Update `index.php`:
```php
$config = [
    'iot_hub_connection_string' => 'HostName=your-hub.azure-devices.net;SharedAccessKeyName=service;SharedAccessKey=your-key',
    'device_id' => 'raspberrypi',
    'iot_hub_name' => 'your-hub-name',
    'storage_account' => 'yourstorageaccount',
    'container' => 'camera-images',
    'computer_vision_endpoint' => 'https://your-region.cognitiveservices.azure.com/',
    'computer_vision_key' => 'your-computer-vision-key'
];
```

## Testing

### 1. Test Raspberry Pi Connection

```bash
# Check device status
az iot hub device-identity show --hub-name your-iot-hub --device-id raspberrypi

# Send test command
az iot hub invoke-device-method --hub-name your-iot-hub --device-id raspberrypi --method-name capture_image --method-payload "{\"test\": true}"
```

### 2. Test Web Dashboard

1. Open browser and navigate to your web server
2. Check status indicators
3. Click "Capture & Analyze Image"
4. Monitor debug output (if enabled)

### 3. Test Computer Vision

```bash
# Test with sample image
curl -X POST "https://your-region.cognitiveservices.azure.com/vision/v3.2/analyze?visualFeatures=Description" \
  -H "Ocp-Apim-Subscription-Key: your-key" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://upload.wikimedia.org/wikipedia/commons/thumb/1/12/ThreeTimeAKCGoldWinner.jpg/300px-ThreeTimeAKCGoldWinner.jpg"}'
```

## Security Considerations

### 1. Environment Variables
Use environment variables for sensitive data:
```php
$config = [
    'iot_hub_connection_string' => $_ENV['IOT_HUB_CONNECTION_STRING'],
    'computer_vision_key' => $_ENV['COMPUTER_VISION_KEY'],
    // ...
];
```

### 2. Network Security
- Use HTTPS in production
- Implement proper authentication
- Restrict access to configuration files
- Use Azure Key Vault for secrets

### 3. Storage Security
- Use SAS tokens for temporary access
- Implement proper CORS policies
- Monitor access logs

## Troubleshooting

### Common Issues

1. **Pi not responding**: Check network connectivity and service status
2. **Images not uploading**: Verify storage account permissions
3. **Analysis failing**: Check Computer Vision quota and limits
4. **Slow performance**: Increase timeouts and optimize image sizes

### Debug Commands

```bash
# Check Pi service logs
sudo journalctl -u camera-service -f

# Check web server logs
sudo tail -f /var/log/apache2/error.log

# Test Azure connectivity
az account show
```

## Performance Optimization

### 1. Image Optimization
- Reduce image resolution for faster upload
- Use JPEG compression
- Implement image resizing

### 2. Caching
- Cache analysis results
- Implement browser caching
- Use CDN for static assets

### 3. Error Handling
- Implement retry logic
- Add proper timeout handling
- Log errors for debugging

## Next Steps

1. Set up monitoring and alerting
2. Implement user authentication
3. Add data visualization
4. Scale for multiple devices
5. Implement real-time notifications