# Raspberry Pi Camera Client

This directory contains the Raspberry Pi camera client that captures images on command and uploads them to Azure Blob Storage.

## Features

- 📷 **Remote Image Capture** - Responds to IoT Hub method calls
- ☁️ **Azure Storage Integration** - Uploads images to blob storage
- 📊 **Telemetry** - Sends device status and events
- 🔄 **Auto-retry** - Automatic reconnection and error handling
- 📝 **Comprehensive Logging** - Detailed logs for debugging
- ⚙️ **Configurable** - Adjustable image quality and settings
- 🛡️ **Secure** - Uses environment variables for credentials

## Quick Start

### 1. Automatic Installation (Recommended)

```bash
# Download and run the installation script
curl -sSL https://raw.githubusercontent.com/yourusername/iot-camera-dashboard/main/raspberry-pi/install.sh | bash
```

### 2. Manual Installation

```bash
# Clone the repository
git clone https://github.com/yourusername/iot-camera-dashboard.git
cd iot-camera-dashboard/raspberry-pi

# Install dependencies
sudo apt update && sudo apt install -y python3 python3-pip
pip3 install -r requirements.txt

# Copy files to application directory
sudo mkdir -p /home/pi/iot-camera
sudo cp *.py *.txt *.sh /home/pi/iot-camera/
sudo chown -R pi:pi /home/pi/iot-camera
```

### 3. Configuration

Edit the configuration file:
```bash
nano /home/pi/iot-camera/config.env
```

Add your Azure credentials:
```bash
DEVICE_CONNECTION_STRING="HostName=your-hub.azure-devices.net;DeviceId=raspberrypi;SharedAccessKey=your-key"
STORAGE_CONNECTION_STRING="DefaultEndpointsProtocol=https;AccountName=youraccount;AccountKey=your-key;EndpointSuffix=core.windows.net"
```

### 4. Test & Run

```bash
cd /home/pi/iot-camera

# Test the setup
./test.sh

# Run manually for testing
./start.sh

# Or install as a system service
sudo systemctl enable iot-camera
sudo systemctl start iot-camera
```

## Files

| File | Description |
|------|-------------|
| `camera_client.py` | Main camera client application |
| `requirements.txt` | Python dependencies |
| `install.sh` | Automated installation script |
| `README.md` | This documentation |

## Supported Methods

The camera client responds to these IoT Hub method calls:

### `capture_image`
Captures an image and uploads to Azure Storage.

**Payload (optional):**
```json
{
  "rotation": 180,
  "effect": "sketch"
}
```

**Response:**
```json
{
  "status": "success",
  "filename": "analyzed_capture_20250627_143022_a1b2c3d4.jpg",
  "blob_url": "https://storage.blob.core.windows.net/camera-images/...",
  "size_bytes": 245760,
  "timestamp": "2025-06-27T14:30:22.123456"
}
```

### `get_device_info`
Returns device information and status.

**Response:**
```json
{
  "status": "success",
  "device_info": {
    "hostname": "raspberrypi",
    "uptime": "up 2 days, 14 hours, 30 minutes",
    "disk_used": "2.1G",
    "disk_available": "26G", 
    "disk_usage_percent": "8%",
    "temperature": "45.2°C",
    "timestamp": "2025-06-27T14:30:22.123456"
  }
}
```

### `test_storage`
Tests Azure Storage connectivity.

**Response:**
```json
{
  "status": "success",
  "storage_info": {
    "container": "camera-images",
    "last_modified": "2025-06-27T14:30:22.123456",
    "account_name": "youraccount",
    "test_time": "2025-06-27T14:30:22.123456"
  }
}
```

### `ping`
Simple connectivity test.

**Response:**
```json
{
  "status": "success",
  "message": "pong",
  "timestamp": "2025-06-27T14:30:22.123456"
}
```

## Configuration Options

Environment variables in `config.env`:

| Variable | Description | Default |
|----------|-------------|---------|
| `DEVICE_CONNECTION_STRING` | IoT Hub device connection string | Required |
| `STORAGE_CONNECTION_STRING` | Azure Storage connection string | Required |
| `CAMERA_WIDTH` | Image width in pixels | 1024 |
| `CAMERA_HEIGHT` | Image height in pixels | 768 |
| `CAMERA_QUALITY` | JPEG quality (1-100) | 85 |

## Service Management

```bash
# Start service
sudo systemctl start iot-camera

# Stop service  
sudo systemctl stop iot-camera

# Enable auto-start on boot
sudo systemctl enable iot-camera

# Disable auto-start
sudo systemctl disable iot-camera

# Check status
sudo systemctl status iot-camera

# View logs
sudo journalctl -u iot-camera -f

# View application logs
tail -f /var/log/camera-client.log
```

## Troubleshooting

### Camera Issues

```bash
# Test camera manually
raspistill -o test.jpg

# Check camera config
vcgencmd get_camera

# Enable camera if disabled
sudo raspi-config nonint do_camera 0
```

### Connection Issues

```bash
# Test network connectivity
ping azure.microsoft.com

# Check DNS resolution
nslookup your-hub.azure-devices.net

# Test IoT Hub connection
az iot hub device-identity show --hub-name your-hub --device-id raspberrypi
```

### Storage Issues

```bash
# Test storage account
az storage container list --connection-string "your-connection-string"

# Check container permissions
az storage container show-permission --name camera-images --connection-string "your-connection-string"
```

### Service Issues

```bash
# Check service status
sudo systemctl status iot-camera

# View detailed logs
sudo journalctl -u iot-camera --no-pager

# Restart service
sudo systemctl restart iot-camera

# Check configuration
source /home/pi/iot-camera/config.env && echo $DEVICE_CONNECTION_STRING
```

## Development

### Running in Development Mode

```bash
cd /home/pi/iot-camera
source venv/bin/activate
source config.env
python camera_client.py
```

### Adding New Methods

1. Add method handler in `camera_client.py`:
```python
async def my_new_method(self, payload=None):
    # Your implementation
    return {"status": "success", "data": "result"}
```

2. Register in `method_handler()`:
```python
elif method_request.name == "my_new_method":
    result = await self.my_new_method(method_request.payload)
    status_code = 200 if result["status"] == "success" else 500
```

### Testing Methods

```bash
# Using Azure CLI
az iot hub invoke-device-method \
  --hub-name your-hub \
  --device-id raspberrypi \
  --method-name capture_image \
  --method-payload '{"rotation": 90}'
```

## Security Notes

- Store credentials in environment variables, never in code
- Use device-specific connection strings, not shared access keys
- Regularly rotate access keys
- Monitor logs for unauthorized access attempts
- Keep system and packages updated

## Performance Optimization

- Adjust image quality based on network bandwidth
- Use lower resolution for faster uploads
- Consider image compression before upload
- Monitor disk space usage
- Implement cleanup of old temporary files

## Updates

To update the camera client:

```bash
cd /home/pi/iot-camera
git pull origin main
sudo systemctl restart iot-camera
```

