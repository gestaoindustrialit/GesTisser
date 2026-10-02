<?php

/** Builds the administration workbook used to export every user in bulk. */
final class UserSpreadsheetExport
{
    public static function columns(): array
    {
        return [
            'id' => 'ID',
            'user_number' => 'N.º colaborador',
            'name' => 'Nome',
            'short_name' => 'Nome curto',
            'title' => 'Título',
            'initials' => 'Sigla',
            'username' => 'Utilizador',
            'email' => 'Email profissional',
            'personal_email' => 'Email pessoal',
            'phone' => 'Telefone',
            'mobile' => 'Telemóvel',
            'access_profile' => 'Perfil de acesso',
            'user_type' => 'Tipo de utilizador',
            'is_admin' => 'Administrador',
            'is_active' => 'Ativo',
            'must_change_password' => 'Alterar password no próximo acesso',
            'pin_only_login' => 'Login apenas com PIN',
            'crm_enabled' => 'Acesso ao CRM',
            'award_profile' => 'Perfil de prémios',
            'award_eligible' => 'Elegível para prémios',
            'email_notifications_active' => 'Notificações por email',
            'sms_notifications_active' => 'Notificações por SMS',
            'send_access_email' => 'Enviar dados de acesso',
            'department_id' => 'ID departamento',
            'department' => 'Departamento',
            'schedule_id' => 'ID turno',
            'schedule_name' => 'Turno',
            'profession' => 'Profissão',
            'category' => 'Categoria',
            'manager_name' => 'Responsável',
            'hire_date' => 'Data de admissão',
            'birth_date' => 'Data de nascimento',
            'termination_date' => 'Data de saída',
            'timezone' => 'Fuso horário',
            'tax_number' => 'NIF',
            'social_security_number' => 'N.º Segurança Social',
            'address' => 'Morada',
            'postal_code' => 'Código postal',
            'parish' => 'Freguesia',
            'municipality' => 'Concelho',
            'district' => 'Distrito',
            'place_of_birth' => 'Naturalidade',
            'nationality' => 'Nacionalidade',
            'citizen_card_number' => 'N.º Cartão de Cidadão',
            'citizen_card_expiry_date' => 'Validade Cartão de Cidadão',
            'marital_status' => 'Estado civil',
            'dependents_count' => 'N.º dependentes',
            'notes' => 'Observações',
            'created_at' => 'Criado em',
            'last_login_at' => 'Último acesso',
        ];
    }

    public static function build(array $users): string
    {
        $columns = self::columns();
        $rows = [array_values($columns)];
        foreach ($users as $user) {
            $row = [];
            foreach ($columns as $field => $label) {
                $value = $user[$field] ?? '';
                if (in_array($field, ['is_admin', 'is_active', 'must_change_password', 'pin_only_login', 'crm_enabled', 'award_eligible', 'email_notifications_active', 'sms_notifications_active', 'send_access_email'], true)) {
                    $value = (int) $value === 1 ? 'Sim' : 'Não';
                }
                $row[] = (string) $value;
            }
            $rows[] = $row;
        }

        $xmlRows = '';
        foreach ($rows as $row) {
            $cells = '';
            foreach ($row as $value) {
                $cells .= '<Cell><Data ss:Type="String">' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Data></Cell>';
            }
            $xmlRows .= '<Row>' . $cells . '</Row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' .
            '<?mso-application progid="Excel.Sheet"?>' .
            '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' .
            '<Worksheet ss:Name="Utilizadores"><Table>' . $xmlRows . '</Table></Worksheet></Workbook>';
    }
}
