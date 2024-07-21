<?php

function db_insert_one_row_od($table_name, $settings)
{
    $query = make_insert_command($table_name, $settings);
    $res = db_query_od($query);
}

function db_insert_one_row($table_name, $settings)
{
    $query = make_insert_command($table_name, $settings);
    return db_query($query);
}

function make_insert_command($table_name, $settings)
{
    global $NL_or_SP;
    $settings_s = make_settings_string($settings);
    $query = "INSERT INTO $table_name SET{$NL_or_SP}$settings_s";
    return $query;
}

// -----------------------------------------------------------------------------

function db_update_one_row_od($table_name, $key_colname, $key_value, $settings, $error_prefix='')
{
    $query = make_update_command($table_name, $key_colname, $key_value, $settings);
    db_query_od($query, $error_prefix);
}

function make_update_command($table_name, $key_colname, $key_value, $settings)
{
    global $NL_or_SP;
    $settings_s = make_settings_string($settings);
    $query = "UPDATE $table_name SET{$NL_or_SP}$settings_s{$NL_or_SP}WHERE $key_colname=$key_value";
    return $query;
}

// -----------------------------------------------------------------------------

$NL_or_SP = "\n"; // newline or space, depending on how you want the query to look.

function make_settings_string($settings)
{
    global $NL_or_SP;
    $settings_a = array();
    foreach ($settings as $column_arg => $value_arg)
    {
        assert(is_string($column_arg));

        if (str_endswith($column_arg, '!q'))
        {
            // The $value_arg already contains any quoting that it needs.
            $column_name = substr($column_arg, 0, -2);
            assert(is_string($value_arg));
            $expression = $value_arg;
        }
        else
        {
            // The $value_arg has not already been quoted.
            $column_name = $column_arg;
            if (is_integer($value_arg) || is_float($value_arg))
            {
                // It doesn't need quoting, just needs to be stringified.
                $expression = strval($value_arg);
                // But note that it wouldn't be *wrong* to quote it.
                // If the type of the column is numeric,
                // MySQL is happy to ignore the quotes.
            }
            elseif (is_string($value_arg))
            {
                $expression = "'" . db_escape($value_arg) . "'";
            }
            elseif (is_null($value_arg))
            {
                // Treat it like an empty string (not like a SQL NULL).
                $expression = "''";
            }
            else
            {
                die("value_arg is $value_arg of type " . gettype($value_arg));
            }
        }
        $settings_a[] = "$column_name=$expression";
    }
    $settings_s = implode(",{$NL_or_SP}", $settings_a);
    return $settings_s;
}

// -----------------------------------------------------------------------------

function db_get_one_row($table_name, $where_condition, $fields_to_get, $type_of_result, $error_msg)
{
    $query = "SELECT $fields_to_get from $table_name where $where_condition";
    $result = db_query_od($query);
    $n_rows = mysqli_num_rows($result);

    if ($n_rows == 1)
    {
        // Normal case
        if ($type_of_result == 'N')
        {
            // Numeric array (keys are small integers):
            return db_fetch_row($result);
        }
        elseif ($type_of_result == 'A')
        {
            // Associative array (keys are column names):
            return db_fetch_assoc($result);
        }
        elseif ($type_of_result == 'O')
        {
            return db_fetch_object($result);
        }
        else
        {
            die("bad value for \$type_of_result: '$type_of_result'");
        }
    }

    // Something's wrong.

    $msg1 = "Table '$table_name' has $n_rows rows matching the condition: $where_condition";
    $msg2 = (
        $n_rows == 0
        ? ( $error_msg ? $error_msg : "Aborting due to above error." )
        : "This should be impossible. Something is very wrong."
    );
    echo "
        <div style='color: red'>
        <p>$msg1</p>
        <p>$msg2</p>
        </div>
    ";
    exit;
}

// -----------------------------------------------------------------------------

function db_query_od($query, $error_prefix="")
// The "od" stands for "or die".
// That is, if the query fails (returns FALSE),
// print an informative error message and then die.
{
    $result = db_query($query);
    if (!$result)
    {
        echo "
            <div style='color:red'>
                <p>$error_prefix</p>
                <p>The query:</p>
                <pre>
                    " . htmlspecialchars($query) . "
                </pre>
                <p>raised the following error:</p>
                <pre>
                    " . htmlspecialchars(db_error()) . "
                </pre>
            </div>
        ";
        exit;
    }
    return $result;
}

// XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
// Only the code below this line 'knows' that we're using mysqli.

function db_init($mode)
{
    if ($mode == 'ro') // read-only
    {
        $db_username = 'pgreader';
        $db_password = 'freeread';
    }
    elseif ($mode == 'rw') // read-write
    {
        $db_username = 'pgwriter';
        $db_password = 'freebks';
    }
    else
    {
        die("Error: db_init invoked with \$mode = '$mode'");
    }

    $db_host = "localhost";
    $db_database_name = "submissions";

    global $mysqli_link;
    $mysqli_link = mysqli_connect($db_host, $db_username, $db_password, $db_database_name);
    if (mysqli_connect_errno($mysqli_link))
    {
        die("Failed to connect to database: " . mysqli_connect_error());
    }
}

function db_escape($s)
{
    global $mysqli_link;
    return mysqli_real_escape_string($mysqli_link, $s);
}

function db_query($query)
{
    global $mysqli_link;
    if (defined('MODE') && MODE == Mode::Development) echo "<!--\nTESTING db_query:\n    $query\n-->\n";
    return mysqli_query($mysqli_link, $query);
}

function db_error()
{
    global $mysqli_link;
    return mysqli_error($mysqli_link);
}

function db_inserted_id()
{
    global $mysqli_link;
    return mysqli_insert_id($mysqli_link);
}

function db_num_rows($result)
{
    return mysqli_num_rows($result);
}

function db_fetch_row($res)
{
    return mysqli_fetch_row($res);
}

function db_fetch_assoc($res)
{
    return mysqli_fetch_assoc($res);
}

function db_fetch_object($res)
{
    return mysqli_fetch_object($res);
}

// vim: sw=4 ts=4 expandtab
