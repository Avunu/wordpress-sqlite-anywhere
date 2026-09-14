//! Conversions between PHP values and turso values.

use ext_php_rs::boxed::ZBox;
use ext_php_rs::types::{ZendHashTable, Zval};
use turso::Value;

/// Convert positional PHP parameters to turso values.
///
/// PHP strings are byte strings: valid UTF-8 travels as TEXT, anything else
/// as a BLOB, which is what the HTTP transport does too.
pub fn params_from_php(params: Option<&ZendHashTable>) -> Result<Vec<Value>, String> {
    let Some(params) = params else {
        return Ok(Vec::new());
    };
    let mut values = Vec::with_capacity(params.len());
    for zval in params.values() {
        values.push(value_from_zval(zval)?);
    }
    Ok(values)
}

fn value_from_zval(zval: &Zval) -> Result<Value, String> {
    if zval.is_null() {
        return Ok(Value::Null);
    }
    if let Some(long) = zval.long() {
        return Ok(Value::Integer(long));
    }
    if let Some(double) = zval.double() {
        return Ok(Value::Real(double));
    }
    if let Some(boolean) = zval.bool() {
        return Ok(Value::Integer(i64::from(boolean)));
    }
    if let Some(string) = zval.zend_str() {
        return Ok(match string.as_str() {
            Ok(text) => Value::Text(text.to_string()),
            Err(_) => Value::Blob(string.as_bytes().to_vec()),
        });
    }
    Err("query parameters must be scalars or null".to_string())
}

/// Convert a turso value to a PHP value.
pub fn value_to_zval(value: Value) -> Result<Zval, String> {
    let mut zval = Zval::new();
    match value {
        Value::Null => zval.set_null(),
        Value::Integer(int) => zval.set_long(int),
        Value::Real(real) => zval.set_double(real),
        Value::Text(text) => zval
            .set_string(&text, false)
            .map_err(|e| format!("failed to create a PHP string: {e:?}"))?,
        Value::Blob(bytes) => zval.set_binary(bytes),
    }
    Ok(zval)
}

/// Build the `array{columns: string[], rows: array[]}` result the driver's
/// transports return.
pub fn result_to_php(
    columns: Vec<String>,
    rows: Vec<Vec<Value>>,
) -> Result<ZBox<ZendHashTable>, String> {
    let mut php_columns = ZendHashTable::new();
    for column in columns {
        php_columns
            .push(column)
            .map_err(|e| format!("failed to build the result: {e:?}"))?;
    }

    let mut php_rows = ZendHashTable::new();
    for row in rows {
        let mut php_row = ZendHashTable::new();
        for value in row {
            php_row
                .push(value_to_zval(value)?)
                .map_err(|e| format!("failed to build the result: {e:?}"))?;
        }
        php_rows
            .push(php_row)
            .map_err(|e| format!("failed to build the result: {e:?}"))?;
    }

    let mut result = ZendHashTable::new();
    result
        .insert("columns", php_columns)
        .map_err(|e| format!("failed to build the result: {e:?}"))?;
    result
        .insert("rows", php_rows)
        .map_err(|e| format!("failed to build the result: {e:?}"))?;
    Ok(result)
}
