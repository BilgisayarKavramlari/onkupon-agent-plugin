<?php
namespace OnKupon\Agent\AI;

class JsonSchemaValidator {
    public function validate( array $data, array $schema ): array {
        $errors = [];
        $this->validate_node( $data, $schema, '$', $errors );
        return [ 'valid' => empty( $errors ), 'errors' => $errors ];
    }

    private function validate_node( $value, array $schema, string $path, array &$errors ): void {
        $type = $schema['type'] ?? null;

        if ( 'object' === $type ) {
            $empty_object = [] === $value && empty( $schema['properties'] );
            if ( ! is_array( $value ) || ( ! $empty_object && ! $this->is_associative( $value ) ) ) {
                $errors[] = $path . ' must be an object';
                return;
            }
            foreach ( $schema['required'] ?? [] as $field ) {
                if ( ! array_key_exists( $field, $value ) ) {
                    $errors[] = 'Missing required field: ' . $path . '.' . $field;
                }
            }
            foreach ( $schema['properties'] ?? [] as $field => $rules ) {
                if ( array_key_exists( $field, $value ) ) {
                    $this->validate_node( $value[ $field ], $rules, $path . '.' . $field, $errors );
                }
            }
            if ( false === ( $schema['additionalProperties'] ?? true ) ) {
                foreach ( array_diff( array_keys( $value ), array_keys( $schema['properties'] ?? [] ) ) as $field ) {
                    if ( str_starts_with( (string) $field, '_onkupon_' ) ) {
                        continue;
                    }
                    $errors[] = 'Unexpected field: ' . $path . '.' . $field;
                }
            }
            return;
        }

        if ( 'array' === $type ) {
            if ( ! is_array( $value ) || $this->is_associative( $value ) ) {
                $errors[] = $path . ' must be an array';
                return;
            }
            if ( isset( $schema['items'] ) ) {
                foreach ( $value as $index => $item ) {
                    $this->validate_node( $item, $schema['items'], $path . '[' . $index . ']', $errors );
                }
            }
            return;
        }

        if ( 'integer' === $type && ! is_int( $value ) ) {
            $errors[] = $path . ' must be an integer';
        } elseif ( 'number' === $type && ! is_int( $value ) && ! is_float( $value ) ) {
            $errors[] = $path . ' must be numeric';
        } elseif ( 'string' === $type && ! is_string( $value ) ) {
            $errors[] = $path . ' must be a string';
        } elseif ( 'boolean' === $type && ! is_bool( $value ) ) {
            $errors[] = $path . ' must be boolean';
        }
    }

    private function is_associative( array $value ): bool {
        if ( [] === $value ) {
            return false;
        }
        return array_keys( $value ) !== range( 0, count( $value ) - 1 );
    }
}
