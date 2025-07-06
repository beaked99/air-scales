class PWADataManager {
  async storeReading(data) {
    // Store EVERYTHING locally first
    const reading = {
      id: crypto.randomUUID(),
      timestamp: Date.now(),
      device_mac: data.master_mac,
      total_weight: data.total_weight,
      device_count: data.device_count,
      devices: data.devices,
      connection_type: data.connection_type,
      sync_status: 'pending',
      session_id: this.getCurrentSessionId()
    };
    
    await this.storeInIndexedDB(reading);
    
    // Try immediate sync if online
    if (navigator.onLine) {
      this.queueForSync(reading);
    }
  }
  
  async syncToBeaker() {
    const pendingData = await this.getPendingData();
    const batches = this.createBatches(pendingData, 50); // 50 readings per batch
    
    for (const batch of batches) {
      try {
        await fetch('https://beaker.ca/api/air-scales/sync', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${this.getAuthToken()}`
          },
          body: JSON.stringify({
            session_id: batch[0].session_id,
            readings: batch,
            device_info: this.getDeviceInfo()
          })
        });
        
        await this.markAsSynced(batch);
        console.log(`✅ Synced ${batch.length} readings to beaker.ca`);
        
      } catch (error) {
        console.log(`❌ Sync failed: ${error.message}`);
        // Will retry later via background sync
      }
    }
  }
}