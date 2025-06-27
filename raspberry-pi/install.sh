#!/bin/bash

# IoT Camera Client Installation Script for Raspberry Pi
# This script sets up the camera client on a Raspberry Pi

set -e

echo "🔧 Installing IoT Camera Client on Raspberry Pi..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running on Raspberry Pi
if ! grep -q "Raspberry Pi" /proc/cpuinfo 2>/dev/null; then
    print_warning "This doesn't appear to be a Raspberry Pi. Some features may not work."
fi

# Update system
print_status "Updating system packages..."
sudo apt update && sudo apt upgrade -y

# Install system dependencies
print_status "Installing system dependencies..."
sudo apt install -y \
    python3 \
    python3-pip \
    python3-venv \
    git \
    curl \
    vim

# Enable camera
print_status "Configuring camera..."
if ! grep -q "^camera_auto_detect=1" /boot/config.txt 2>/dev/null; then
    echo "camera_auto_detect=1" | sudo tee -a /boot/config.txt
    print_status "Camera enabled in config.txt"
fi

# Enable camera interface (for older Pi models)
sudo raspi-config nonint do_camera 0 2>/dev/null || print_warning "Could not enable camera interface via raspi-config"

# Create application directory
APP_DIR="/home/pi/iot-camera"
print_status "Creating application directory: $APP_DIR"
mkdir -p $APP_DIR
cd $APP_DIR

# Create Python virtual environment
print_status "Creating Python virtual environment..."
python3 -m venv venv
source venv/bin/activate

# Install Python dependencies
print_status "Installing Python dependencies..."
pip install --upgrade pip
pip install -r requirements.txt

# Create configuration file template
print_status "Creating configuration template..."
cat > config.env << 'EOF'
# Azure IoT Hub Device Connection String
# Get this from: Azure Portal > IoT Hub > Devices > Your Device > Primary Connection String
DEVICE_CONNECTION_STRING="HostName=your-hub.azure-devices.net;DeviceId=your-device;SharedAccessKey=your-key"

# Azure Storage Connection String  
# Get this from: Azure Portal > Storage Account > Access Keys > Connection String
STORAGE_CONNECTION_STRING="DefaultEndpointsProtocol=https;AccountName=your-account;AccountKey=your-key;EndpointSuffix=core.windows.net"

# Optional: Camera settings
CAMERA_WIDTH=1024
CAMERA_HEIGHT=768
CAMERA_QUALITY=85
EOF

# Create systemd service file
print_status "Creating systemd service..."
sudo tee /etc/systemd/system/iot-camera.service > /dev/null << EOF
[Unit]
Description=IoT Camera Client
After=network.target
Wants=network.target

[Service]
Type=simple
User=pi
Group=pi
WorkingDirectory=$APP_DIR
Environment=PATH=$APP_DIR/venv/bin
ExecStartPre=/bin/bash -c 'source $APP_DIR/config.env'
ExecStart=$APP_DIR/venv/bin/python camera_client.py
EnvironmentFile=$APP_DIR/config.env
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

# Security settings
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$APP_DIR /tmp /var/log

[Install]
WantedBy=multi-user.target
EOF

# Create log rotation
print_status "Setting up log rotation..."
sudo tee /etc/logrotate.d/iot-camera > /dev/null << EOF
/var/log/camera-client.log {
    daily
    rotate 7
    compress
    missingok
    notifempty
    create 644 pi pi
}
EOF

# Create startup script
print_status "Creating startup script..."
cat > start.sh << 'EOF'
#!/bin/bash
cd /home/pi/iot-camera
source venv/bin/activate
source config.env
python camera_client.py
EOF
chmod +x start.sh

# Create test script
print_status "Creating test script..."
cat > test.sh << 'EOF'
#!/bin/bash
echo "🧪 Testing IoT Camera Client..."

# Test camera
echo "📷 Testing camera..."
raspistill -t 1000 -o /tmp/test.jpg
if [ $? -eq 0 ]; then
    echo "✅ Camera test passed"
    rm -f /tmp/test.jpg
else
    echo "❌ Camera test failed"
fi

# Test Python environment
echo "🐍 Testing Python environment..."
cd /home/pi/iot-camera
source venv/bin/activate
python -c "import azure.iot.device, azure.storage.blob; print('✅ Python dependencies OK')"

# Test configuration
echo "🔧 Checking configuration..."
if [ -f "config.env" ]; then
    source config.env
    if [ -n "$DEVICE_CONNECTION_STRING" ] && [ "$DEVICE_CONNECTION_STRING" != "HostName=your-hub.azure-devices.net;DeviceId=your-device;SharedAccessKey=your-key" ]; then
        echo "✅ Device connection string configured"
    else
        echo "❌ Device connection string not configured"
    fi
    
    if [ -n "$STORAGE_CONNECTION_STRING" ] && [ "$STORAGE_CONNECTION_STRING" != "DefaultEndpointsProtocol=https;AccountName=your-account;AccountKey=your-key;EndpointSuffix=core.windows.net" ]; then
        echo "✅ Storage connection string configured"
    else
        echo "❌ Storage connection string not configured"
    fi
else
    echo "❌ Configuration file not found"
fi

echo "🏁 Test complete!"
EOF
chmod +x test.sh

# Set permissions
sudo chown -R pi:pi $APP_DIR

# Reload systemd
sudo systemctl daemon-reload

print_success "Installation completed!"
echo
print_status "Next steps:"
echo "1. Edit configuration: nano $APP_DIR/config.env"
echo "2. Add your Azure connection strings"
echo "3. Test the setup: cd $APP_DIR && ./test.sh"
echo "4. Start the service: sudo systemctl enable iot-camera && sudo systemctl start iot-camera"
echo "5. Check status: sudo systemctl status iot-camera"
echo "6. View logs: sudo journalctl -u iot-camera -f"
echo
print_warning "⚠️  Don't forget to reboot if camera was just enabled!"
echo
print_success "🎉 IoT Camera Client installation complete!"