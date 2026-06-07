<?php

declare(strict_types=1);

class IPSMotionLightControl extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Sensors', '[]');
        $this->RegisterPropertyString('Lights', '[]');
        $this->RegisterPropertyInteger('OffDelaySeconds', 120);
        $this->RegisterPropertyInteger('DayDimValue', 80);
        $this->RegisterPropertyInteger('NightDimValue', 30);
        $this->RegisterPropertyInteger('NightVariableID', 0);
        $this->RegisterPropertyBoolean('NightVariableInvert', false);

        $this->RegisterAttributeString('RegisteredSensorIDs', '[]');
        $this->RegisterAttributeBoolean('LightsOn', false);

        $this->RegisterVariableBoolean('Active', 'Aktiv', '~Switch', 1);
        $this->EnableAction('Active');

        $this->RegisterTimer('SwitchOffTimer', 0, 'BLS_TurnOff($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetSummary(sprintf(
            'Sensoren: %d | Leuchten: %d | Timer: %ds',
            count($this->GetSensorIDs()),
            count($this->GetLights()),
            $this->ReadPropertyInteger('OffDelaySeconds')
        ));

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->UnregisterSensorMessages();

        $sensorIDs = $this->GetSensorIDs();
        foreach ($sensorIDs as $sensorID) {
            $this->RegisterReference($sensorID);
            $this->RegisterMessage($sensorID, VM_UPDATE);
        }

        foreach ($this->GetLights() as $light) {
            if ($light['SwitchVariableID'] > 0) {
                $this->RegisterReference($light['SwitchVariableID']);
            }
            if ($light['DimVariableID'] > 0) {
                $this->RegisterReference($light['DimVariableID']);
            }
        }

        $nightVariableID = $this->ReadPropertyInteger('NightVariableID');
        if ($nightVariableID > 0) {
            $this->RegisterReference($nightVariableID);
        }

        $this->WriteAttributeString('RegisteredSensorIDs', json_encode(array_values($sensorIDs)));

        if (!$this->GetValue('Active')) {
            $this->SetTimerInterval('SwitchOffTimer', 0);
            return;
        }

        $this->EvaluateState();
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Active':
                SetValueBoolean($this->GetIDForIdent('Active'), (bool) $Value);
                if ((bool) $Value) {
                    $this->EvaluateState();
                } else {
                    $this->SetTimerInterval('SwitchOffTimer', 0);
                    $this->SwitchLights(false, true);
                }
                break;

            default:
                throw new Exception('Invalid Ident');
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message !== VM_UPDATE) {
            return;
        }

        if (!$this->GetValue('Active')) {
            return;
        }

        $this->EvaluateState();
    }

    public function TurnOff(): void
    {
        $this->SetTimerInterval('SwitchOffTimer', 0);

        if (!$this->GetValue('Active')) {
            return;
        }

        if ($this->IsAnySensorActive()) {
            return;
        }

        $this->SwitchLights(false, true);
    }

    private function EvaluateState(): void
    {
        if ($this->IsAnySensorActive()) {
            $this->SetTimerInterval('SwitchOffTimer', 0);
            $this->SwitchLights(true);
            return;
        }

        $delaySeconds = max(1, $this->ReadPropertyInteger('OffDelaySeconds'));
        $this->SetTimerInterval('SwitchOffTimer', $delaySeconds * 1000);
    }

    private function IsAnySensorActive(): bool
    {
        foreach ($this->GetSensorIDs() as $sensorID) {
            if (!$this->IsValidBoolVariable($sensorID)) {
                continue;
            }

            if ((bool) GetValueBoolean($sensorID)) {
                return true;
            }
        }

        return false;
    }

    private function SwitchLights(bool $turnOn, bool $force = false): void
    {
        // Befehle nur senden, wenn sich der Soll-Zustand tatsächlich ändert.
        // Bewegungsmelder senden bei anhaltender Bewegung sehr häufig erneut "true";
        // ohne diese Sperre würde bei jedem Update erneut RequestAction auf alle
        // Leuchten abgefeuert und das Funk-Gateway geflutet (Modul "hängt").
        if (!$force && $this->ReadAttributeBoolean('LightsOn') === $turnOn) {
            return;
        }

        $this->WriteAttributeBoolean('LightsOn', $turnOn);
        $this->SendDebug('SwitchLights', ($turnOn ? 'EIN' : 'AUS') . ($force ? ' (force)' : ''), 0);

        $dimValue = $this->GetCurrentDimValue();

        foreach ($this->GetLights() as $light) {
            $switchVariableID = $light['SwitchVariableID'];
            $dimVariableID = $light['DimVariableID'];

            // Dimmer nur beim Einschalten setzen
            if ($turnOn && $dimVariableID > 0 && $this->IsValidIntegerVariable($dimVariableID)) {
                RequestAction($dimVariableID, $dimValue);
            }

            if ($switchVariableID > 0 && $this->IsValidBoolVariable($switchVariableID)) {
                RequestAction($switchVariableID, $turnOn);
            }
        }
    }

    private function GetCurrentDimValue(): int
    {
        if ($this->IsNightMode()) {
            return $this->ClampDimValue($this->ReadPropertyInteger('NightDimValue'));
        }

        return $this->ClampDimValue($this->ReadPropertyInteger('DayDimValue'));
    }

    private function IsNightMode(): bool
    {
        $nightVariableID = $this->ReadPropertyInteger('NightVariableID');
        if ($nightVariableID <= 0 || !$this->IsValidBoolVariable($nightVariableID)) {
            return false;
        }

        $nightValue = (bool) GetValueBoolean($nightVariableID);
        $invert = $this->ReadPropertyBoolean('NightVariableInvert');
        return $invert ? !$nightValue : $nightValue;
    }

    private function ClampDimValue(int $value): int
    {
        if ($value < 0) {
            return 0;
        }

        if ($value > 100) {
            return 100;
        }

        return $value;
    }

    private function GetSensorIDs(): array
    {
        $sensors = $this->DecodeArrayProperty('Sensors');
        $ids = [];

        foreach ($sensors as $sensor) {
            if (!isset($sensor['VariableID'])) {
                continue;
            }

            $variableID = (int) $sensor['VariableID'];
            if ($variableID <= 0) {
                continue;
            }

            $ids[] = $variableID;
        }

        return array_values(array_unique($ids));
    }

    private function GetLights(): array
    {
        $lights = $this->DecodeArrayProperty('Lights');
        $result = [];

        foreach ($lights as $light) {
            $result[] = [
                'Name'            => isset($light['Name']) ? (string) $light['Name'] : '',
                'SwitchVariableID' => isset($light['SwitchVariableID']) ? (int) $light['SwitchVariableID'] : 0,
                'DimVariableID'    => isset($light['DimVariableID']) ? (int) $light['DimVariableID'] : 0
            ];
        }

        return $result;
    }

    private function DecodeArrayProperty(string $propertyName): array
    {
        $raw = $this->ReadPropertyString($propertyName);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function IsValidBoolVariable(int $variableID): bool
    {
        if ($variableID <= 0 || !@IPS_VariableExists($variableID)) {
            return false;
        }

        $variable = IPS_GetVariable($variableID);
        return $variable['VariableType'] === VARIABLETYPE_BOOLEAN;
    }

    private function IsValidIntegerVariable(int $variableID): bool
    {
        if ($variableID <= 0 || !@IPS_VariableExists($variableID)) {
            return false;
        }

        $variable = IPS_GetVariable($variableID);
        return $variable['VariableType'] === VARIABLETYPE_INTEGER;
    }

    private function UnregisterSensorMessages(): void
    {
        $registered = json_decode($this->ReadAttributeString('RegisteredSensorIDs'), true);
        if (!is_array($registered)) {
            $registered = [];
        }

        foreach ($registered as $sensorID) {
            $sensorID = (int) $sensorID;
            if ($sensorID <= 0) {
                continue;
            }
            @$this->UnregisterMessage($sensorID, VM_UPDATE);
        }
    }
}
