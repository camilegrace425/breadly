<?php
// File Location: breadly/src/ListableData.php

interface ListableData {
    /**
     * Fetches the primary list of data for the manager.
     * Must be implemented by all classes using this interface.
     */
    public function fetchAllData(): array;
}
?>